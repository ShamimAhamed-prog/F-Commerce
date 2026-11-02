<?php

namespace App\Domain\Accounting;

use App\Models\CapitalContribution;
use App\Models\CodSettlement;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\OwnerDrawing;
use App\Models\Order;
use App\Models\StockMovement;

/**
 * Turns business events into balanced journal entries (spec §6.3).
 * Revenue and cost of goods are SEPARATE entries so a return can reverse the revenue without
 * wrongly reversing cost (goods are only restocked after inspection).
 */
class Poster
{
    public function __construct(private Ledger $ledger) {}

    private function fmt(float|string $v): string
    {
        return bcadd((string) $v, '0', 2);
    }

    private function pos(string $amount): bool
    {
        return bccomp($amount, '0', 2) > 0;
    }

    // ----- stock ledger -> inventory asset (Inventory account always equals stock valuation)

    /** Opening stock, adjustments and returns. Sales are posted with the order; purchases with the payment account. */
    public function stock(StockMovement $m): ?JournalEntry
    {
        $amount = $this->fmt(bcmul((string) abs($m->qty), (string) $m->unit_cost, 4));
        if (! $this->pos($amount)) {
            return null; // no cost known: nothing to value
        }
        $in = $m->qty > 0;
        [$counter, $memo] = match ($m->type) {
            'opening' => [ChartOfAccounts::OPENING_EQUITY, 'Opening stock'],
            'return' => [ChartOfAccounts::COGS, 'Stock returned to inventory'],
            default => [ChartOfAccounts::STOCK_ADJUSTMENT, $m->qty > 0 ? 'Stock adjustment (increase)' : 'Stock adjustment (decrease)'],
        };
        $inv = ChartOfAccounts::INVENTORY;

        return $this->ledger->post([
            'memo' => $memo.($m->reason ? ": {$m->reason}" : ''), 'source_type' => 'stock', 'source_key' => "stock:{$m->id}", 'source_id' => $m->id,
            'lines' => $in
                ? [['account' => $inv, 'debit' => $amount], ['account' => $counter, 'credit' => $amount]]
                : [['account' => $counter, 'debit' => $amount], ['account' => $inv, 'credit' => $amount]],
        ]);
    }

    /** Stock received and paid for from a cash/bank/MFS account: Dr Inventory / Cr that account. Not COGS. */
    public function stockPurchase(StockMovement $m, FinancialAccount $paidFrom): ?JournalEntry
    {
        $amount = $this->fmt(bcmul((string) $m->qty, (string) $m->unit_cost, 4));
        if (! $this->pos($amount)) {
            return null;
        }

        return $this->ledger->post([
            'memo' => 'Inventory purchase'.($m->reason ? ": {$m->reason}" : ''), 'source_type' => 'stock', 'source_key' => "stock:{$m->id}", 'source_id' => $m->id,
            'lines' => [['account' => ChartOfAccounts::INVENTORY, 'debit' => $amount], ['account' => $paidFrom->account->code, 'credit' => $amount]],
        ]);
    }

    // ----- sales

    /**
     * On dispatch: recognise the sale. COD owed by the courier is a RECEIVABLE, not cash; delivery does not
     * settle it. Dr advance cash + Dr COD receivable + Dr discounts / Cr sales, delivery, other income, tax.
     */
    public function orderShipped(Order $o): void
    {
        $discount = bcadd((string) $o->item_discount, (string) $o->order_discount, 2);
        $lines = array_values(array_filter([
            $this->pos($this->fmt($o->advance)) ? ['account' => ChartOfAccounts::CASH, 'debit' => $this->fmt($o->advance), 'description' => 'Advance received'] : null,
            $this->pos($this->fmt($o->cod_amount)) ? ['account' => ChartOfAccounts::COD_RECEIVABLE, 'debit' => $this->fmt($o->cod_amount), 'description' => 'COD to collect'] : null,
            $this->pos($discount) ? ['account' => ChartOfAccounts::SALES_DISCOUNTS, 'debit' => $discount] : null,
            $this->pos($this->fmt($o->items_subtotal)) ? ['account' => ChartOfAccounts::SALES, 'credit' => $this->fmt($o->items_subtotal)] : null,
            $this->pos($this->fmt($o->shipping_charge)) ? ['account' => ChartOfAccounts::DELIVERY_INCOME, 'credit' => $this->fmt($o->shipping_charge)] : null,
            $this->pos($this->fmt($o->other_charges)) ? ['account' => ChartOfAccounts::OTHER_INCOME, 'credit' => $this->fmt($o->other_charges)] : null,
            $this->pos($this->fmt($o->tax_amount)) ? ['account' => ChartOfAccounts::TAX_PAYABLE, 'credit' => $this->fmt($o->tax_amount)] : null,
        ]));
        if (count($lines) >= 2) {
            $this->ledger->post(['memo' => "Sale {$o->order_number}", 'source_type' => 'order_revenue', 'source_key' => "order:{$o->id}:revenue", 'source_id' => $o->id, 'lines' => $lines]);
        }

        // Cost of goods sold at the weighted-average cost recorded on each stock movement.
        $cost = '0.00';
        foreach (StockMovement::where('source_type', $o->getMorphClass())->where('source_id', $o->id)->where('type', 'sale')->get() as $m) {
            $cost = bcadd($cost, bcmul((string) abs($m->qty), (string) $m->unit_cost, 4), 4);
        }
        $cost = $this->fmt($cost);
        if ($this->pos($cost)) {
            $this->ledger->post([
                'memo' => "Cost of goods sold {$o->order_number}", 'source_type' => 'order_cogs', 'source_key' => "order:{$o->id}:cogs", 'source_id' => $o->id,
                'lines' => [['account' => ChartOfAccounts::COGS, 'debit' => $cost], ['account' => ChartOfAccounts::INVENTORY, 'credit' => $cost]],
            ]);
        }
    }

    /** Return: reverse the revenue entry only (original kept). COGS stays until goods are inspected and restocked. */
    public function orderReturned(Order $o): void
    {
        if (CodSettlement::where('order_id', $o->id)->exists()) {
            throw new AccountingException('COD for this order has already been settled; resolve the refund before marking it returned.');
        }
        if ($e = JournalEntry::where('source_key', "order:{$o->id}:revenue")->first()) {
            $this->ledger->reverse($e, "Return of {$o->order_number}");
        }
    }

    // ----- money in / out

    public function expense(Expense $e): JournalEntry
    {
        return $this->ledger->post([
            'date' => $e->expense_date->toDateString(), 'memo' => 'Expense: '.$e->category->name.($e->vendor ? " ({$e->vendor})" : ''),
            'source_type' => 'expense', 'source_key' => "expense:{$e->id}", 'source_id' => $e->id,
            'lines' => [['account' => $e->category->account->code, 'debit' => $this->fmt($e->amount), 'description' => $e->description],
                ['account' => $e->financialAccount->account->code, 'credit' => $this->fmt($e->amount)]],
        ]);
    }

    /** Owner money in is equity, never revenue. */
    public function capital(CapitalContribution $c): JournalEntry
    {
        return $this->ledger->post([
            'date' => $c->contribution_date->toDateString(), 'memo' => 'Owner capital contribution', 'source_type' => 'capital',
            'source_key' => "capital:{$c->id}", 'source_id' => $c->id,
            'lines' => [['account' => $c->financialAccount->account->code, 'debit' => $this->fmt($c->amount)], ['account' => ChartOfAccounts::OWNER_CAPITAL, 'credit' => $this->fmt($c->amount)]],
        ]);
    }

    public function drawing(OwnerDrawing $d): JournalEntry
    {
        return $this->ledger->post([
            'date' => $d->drawing_date->toDateString(), 'memo' => 'Owner drawing', 'source_type' => 'drawing',
            'source_key' => "drawing:{$d->id}", 'source_id' => $d->id,
            'lines' => [['account' => ChartOfAccounts::OWNER_DRAWINGS, 'debit' => $this->fmt($d->amount)], ['account' => $d->financialAccount->account->code, 'credit' => $this->fmt($d->amount)]],
        ]);
    }

    /** COD remittance settles the receivable (it is not new revenue). The courier fee is expensed once. */
    public function codSettlement(CodSettlement $s): JournalEntry
    {
        $cleared = bcadd((string) $s->received, (string) $s->fee, 2);
        $lines = array_values(array_filter([
            ['account' => $s->financialAccount->account->code, 'debit' => $this->fmt($s->received)],
            $this->pos($this->fmt($s->fee)) ? ['account' => ChartOfAccounts::COURIER_EXPENSE, 'debit' => $this->fmt($s->fee), 'description' => 'Courier fee deducted'] : null,
            ['account' => ChartOfAccounts::COD_RECEIVABLE, 'credit' => $cleared],
        ], fn ($l) => $l && (! isset($l['debit']) || $this->pos($l['debit']))));

        return $this->ledger->post([
            'date' => $s->settled_date->toDateString(), 'memo' => "COD settlement {$s->order->order_number}", 'source_type' => 'cod_settlement',
            'source_key' => "cod:{$s->id}", 'source_id' => $s->id, 'lines' => $lines,
        ]);
    }

    /** Loan received: Dr cash/bank, Cr Loans payable. A liability, never income. */
    public function loanReceived(Loan $l): JournalEntry
    {
        return $this->ledger->post([
            'date' => $l->received_date->toDateString(), 'memo' => "Loan received: {$l->lender}", 'source_type' => 'loan',
            'source_key' => "loan:{$l->id}", 'source_id' => $l->id,
            'lines' => [['account' => $l->financialAccount->account->code, 'debit' => $this->fmt($l->principal)], ['account' => ChartOfAccounts::LOANS_PAYABLE, 'credit' => $this->fmt($l->principal)]],
        ]);
    }

    /** Repayment: principal reduces the liability; interest is an expense. */
    public function loanRepayment(LoanTransaction $t): JournalEntry
    {
        $total = bcadd((string) $t->principal, (string) $t->interest, 2);
        $lines = array_values(array_filter([
            $this->pos($this->fmt($t->principal)) ? ['account' => ChartOfAccounts::LOANS_PAYABLE, 'debit' => $this->fmt($t->principal)] : null,
            $this->pos($this->fmt($t->interest)) ? ['account' => ChartOfAccounts::INTEREST, 'debit' => $this->fmt($t->interest)] : null,
            ['account' => $t->financialAccount->account->code, 'credit' => $total],
        ]));

        return $this->ledger->post([
            'date' => $t->tx_date->toDateString(), 'memo' => "Loan repayment: {$t->loan->lender}", 'source_type' => 'loan',
            'source_key' => "loan_tx:{$t->id}", 'source_id' => $t->id, 'lines' => $lines,
        ]);
    }
}
