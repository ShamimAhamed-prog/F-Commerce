<?php

namespace App\Domain\Accounting;

use App\Domain\Tenancy\CurrentTenant;
use App\Models\Account;
use App\Models\FinancialAccount;
use Illuminate\Support\Facades\DB;

/**
 * Reports are always computed fresh from the ledger (never cached): they cannot disagree with the GL.
 * Normal balances: asset/cogs/expense = debit − credit; liability/equity/income = credit − debit
 * (so contra accounts such as sales returns/discounts reduce income by carrying a negative balance).
 */
class ReportService
{
    private function tid(): int
    {
        return app(CurrentTenant::class)->id();
    }

    /** @return \Illuminate\Support\Collection account_id => {debit, credit} between dates (null = unbounded) */
    private function sums(?string $from, ?string $to)
    {
        return DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.tenant_id', $this->tid())
            ->when($from, fn ($q) => $q->whereDate('e.transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('e.transaction_date', '<=', $to))
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')->get()->keyBy('account_id');
    }

    private function balance(Account $a, $row): string
    {
        $d = (string) ($row->debit ?? '0');
        $c = (string) ($row->credit ?? '0');

        return $a->isDebitNormal() ? bcsub($d, $c, 2) : bcsub($c, $d, 2);
    }

    public function trialBalance(string $asOf): array
    {
        ChartOfAccounts::ensure();
        $sums = $this->sums(null, $asOf);
        $rows = [];
        $td = $tc = '0.00';
        foreach (Account::orderBy('code')->get() as $a) {
            $r = $sums[$a->id] ?? null;
            if (! $r) {
                continue;
            }
            $net = bcsub((string) $r->debit, (string) $r->credit, 2); // present net balance on its natural side
            $dr = bccomp($net, '0', 2) > 0 ? $net : '0.00';
            $cr = bccomp($net, '0', 2) < 0 ? bcmul($net, '-1', 2) : '0.00';
            $td = bcadd($td, $dr, 2);
            $tc = bcadd($tc, $cr, 2);
            $rows[] = ['code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'debit' => $dr, 'credit' => $cr];
        }

        return ['as_of' => $asOf, 'rows' => $rows, 'total_debit' => $td, 'total_credit' => $tc, 'balanced' => bccomp($td, $tc, 2) === 0];
    }

    public function profitAndLoss(string $from, string $to): array
    {
        ChartOfAccounts::ensure();
        $sums = $this->sums($from, $to);
        $groups = ['income' => [], 'cogs' => [], 'expense' => []];
        $totals = ['income' => '0.00', 'cogs' => '0.00', 'expense' => '0.00'];

        foreach (Account::whereIn('type', array_keys($groups))->orderBy('code')->get() as $a) {
            if (! isset($sums[$a->id])) {
                continue;
            }
            $b = $this->balance($a, $sums[$a->id]);
            $groups[$a->type][] = ['code' => $a->code, 'name' => $a->name, 'amount' => $b];
            $totals[$a->type] = bcadd($totals[$a->type], $b, 2);
        }
        $gross = bcsub($totals['income'], $totals['cogs'], 2);

        return [
            'from' => $from, 'to' => $to, 'income' => $groups['income'], 'cogs' => $groups['cogs'], 'expenses' => $groups['expense'],
            'total_income' => $totals['income'], 'total_cogs' => $totals['cogs'], 'gross_profit' => $gross,
            'total_expenses' => $totals['expense'], 'net_profit' => bcsub($gross, $totals['expense'], 2),
        ];
    }

    public function balanceSheet(string $asOf): array
    {
        ChartOfAccounts::ensure();
        $sums = $this->sums(null, $asOf);
        $sec = ['asset' => [], 'liability' => [], 'equity' => []];
        $tot = ['asset' => '0.00', 'liability' => '0.00', 'equity' => '0.00'];
        $earnings = '0.00';

        foreach (Account::orderBy('code')->get() as $a) {
            $r = $sums[$a->id] ?? null;
            if (! $r) {
                continue;
            }
            $b = $this->balance($a, $r);
            if (isset($sec[$a->type])) {
                $sec[$a->type][] = ['code' => $a->code, 'name' => $a->name, 'amount' => $b];
                $tot[$a->type] = bcadd($tot[$a->type], $b, 2);
            } else { // income adds to, cogs/expense subtract from, cumulative earnings
                $earnings = $a->type === 'income' ? bcadd($earnings, $b, 2) : bcsub($earnings, $b, 2);
            }
        }
        // Profit not yet closed into retained earnings is shown inside equity.
        $sec['equity'][] = ['code' => '', 'name' => 'Current earnings (not yet closed)', 'amount' => $earnings];
        $tot['equity'] = bcadd($tot['equity'], $earnings, 2);
        $lPlusE = bcadd($tot['liability'], $tot['equity'], 2);

        return [
            'as_of' => $asOf, 'assets' => $sec['asset'], 'liabilities' => $sec['liability'], 'equity' => $sec['equity'],
            'total_assets' => $tot['asset'], 'total_liabilities' => $tot['liability'], 'total_equity' => $tot['equity'],
            'balanced' => bccomp($tot['asset'], $lPlusE, 2) === 0,
        ];
    }

    public function ledger(Account $account, string $from, string $to): array
    {
        $opening = $this->sums(null, date('Y-m-d', strtotime("$from -1 day")))[$account->id] ?? null;
        $running = $opening ? $this->balance($account, $opening) : '0.00';
        $openingBal = $running;

        $lines = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.tenant_id', $this->tid())->where('l.account_id', $account->id)
            ->whereDate('e.transaction_date', '>=', $from)->whereDate('e.transaction_date', '<=', $to)->orderBy('e.transaction_date')->orderBy('e.id')->orderBy('l.id')
            ->get(['e.id as entry_id', 'e.entry_number', 'e.transaction_date as date', 'e.memo', 'l.debit', 'l.credit', 'l.description'])
            ->map(function ($l) use ($account, &$running) {
                $delta = $account->isDebitNormal() ? bcsub((string) $l->debit, (string) $l->credit, 2) : bcsub((string) $l->credit, (string) $l->debit, 2);
                $running = bcadd($running, $delta, 2);

                return ['entry_id' => $l->entry_id, 'entry_number' => $l->entry_number, 'date' => substr((string) $l->date, 0, 10), 'memo' => $l->memo, 'description' => $l->description,
                    'debit' => (string) $l->debit, 'credit' => (string) $l->credit, 'balance' => $running];
            })->all();

        return ['account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'from' => $from, 'to' => $to, 'opening' => $openingBal, 'lines' => $lines, 'closing' => $running];
    }

    /** Direct cash-flow: movements through cash/bank/MFS accounts, grouped by what caused them. */
    public function cashFlow(string $from, string $to): array
    {
        ChartOfAccounts::ensure();
        $ids = FinancialAccount::pluck('account_id')->all();
        $opening = '0.00';
        foreach ($this->sums(null, date('Y-m-d', strtotime("$from -1 day"))) as $id => $r) {
            if (in_array($id, $ids)) {
                $opening = bcadd($opening, bcsub((string) $r->debit, (string) $r->credit, 2), 2);
            }
        }
        $labels = ['order_revenue' => 'Advances received with orders', 'cod_settlement' => 'COD collected from couriers', 'expense' => 'Expenses paid',
            'capital' => 'Owner capital in', 'drawing' => 'Owner drawings', 'stock' => 'Inventory purchases', 'manual' => 'Other', 'reversal' => 'Reversals'];
        $rows = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.tenant_id', $this->tid())->whereIn('l.account_id', $ids)->whereDate('e.transaction_date', '>=', $from)->whereDate('e.transaction_date', '<=', $to)
            ->groupBy('e.source_type')->selectRaw('e.source_type as src, SUM(l.debit) as dr, SUM(l.credit) as cr')->get();

        $in = $out = [];
        $tin = $tout = '0.00';
        foreach ($rows as $r) {
            $net = bcsub((string) $r->dr, (string) $r->cr, 2);
            $label = $labels[$r->src] ?? ucfirst((string) $r->src);
            if (bccomp($net, '0', 2) >= 0) {
                $in[] = ['label' => $label, 'amount' => $net];
                $tin = bcadd($tin, $net, 2);
            } else {
                $amt = bcmul($net, '-1', 2);
                $out[] = ['label' => $label, 'amount' => $amt];
                $tout = bcadd($tout, $amt, 2);
            }
        }

        return ['from' => $from, 'to' => $to, 'opening' => $opening, 'inflows' => $in, 'outflows' => $out, 'total_in' => $tin, 'total_out' => $tout,
            'closing' => bcsub(bcadd($opening, $tin, 2), $tout, 2)];
    }

    /** Net balance of one account code as of a date (used by the overview and dashboard). */
    public function balanceOf(string $code, ?string $asOf = null): string
    {
        $a = Account::where('code', $code)->first();
        if (! $a) {
            return '0.00';
        }

        return $this->balance($a, $this->sums(null, $asOf)[$a->id] ?? null);
    }

    /** Delivered/shipped orders with COD still owed (a receivable per order). */
    public function codReceivables(): array
    {
        $settled = DB::table('cod_settlements')->where('tenant_id', $this->tid())->groupBy('order_id')
            ->selectRaw('order_id, SUM(received + fee) as cleared')->pluck('cleared', 'order_id');

        return DB::table('orders')->where('tenant_id', $this->tid())->whereIn('status', ['shipped', 'delivered', 'delivery_failed'])->where('cod_amount', '>', 0)
            ->orderBy('id')->get(['id', 'order_number', 'customer_name', 'status', 'cod_amount', 'created_at'])
            ->map(function ($o) use ($settled) {
                $out = bcsub((string) $o->cod_amount, (string) ($settled[$o->id] ?? 0), 2);

                return ['id' => $o->id, 'order_number' => $o->order_number, 'customer_name' => $o->customer_name, 'status' => $o->status,
                    'cod_amount' => (string) $o->cod_amount, 'cleared' => bcadd((string) ($settled[$o->id] ?? 0), '0', 2), 'outstanding' => $out];
            })->filter(fn ($o) => bccomp($o['outstanding'], '0', 2) > 0)->values()->all();
    }

    /** Expenses by category (advertising called out), by month, and top vendors. Paid, non-void expenses only. */
    public function expenseSummary(string $from, string $to): array
    {
        $base = DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->where('e.tenant_id', $this->tid())->where('e.status', 'paid')->whereDate('e.expense_date', '>=', $from)->whereDate('e.expense_date', '<=', $to);
        $fmt = fn ($v) => bcadd((string) $v, '0', 2);
        $byCat = (clone $base)->groupBy('c.name')->orderByRaw('SUM(e.amount) DESC')->selectRaw('c.name as label, SUM(e.amount) as amount, COUNT(*) as n')->get()
            ->map(fn ($r) => ['label' => $r->label, 'amount' => $fmt($r->amount), 'count' => (int) $r->n])->all();
        $total = array_reduce($byCat, fn ($a, $r) => bcadd($a, $r['amount'], 2), '0.00');
        $ads = collect($byCat)->firstWhere('label', 'Advertising / marketing')['amount'] ?? '0.00';
        $byMonth = (clone $base)->get(['e.expense_date', 'e.amount'])->groupBy(fn ($r) => substr((string) $r->expense_date, 0, 7))
            ->map(fn ($g, $m) => ['label' => $m, 'amount' => $fmt($g->sum('amount'))])->sortKeys()->values()->all();
        $vendors = (clone $base)->whereNotNull('e.vendor')->groupBy('e.vendor')->orderByRaw('SUM(e.amount) DESC')->limit(10)->selectRaw('e.vendor as label, SUM(e.amount) as amount')->get()
            ->map(fn ($r) => ['label' => $r->label, 'amount' => $fmt($r->amount)])->all();
        $adVendors = (clone $base)->where('c.name', 'Advertising / marketing')->groupBy('e.vendor')->orderByRaw('SUM(e.amount) DESC')->selectRaw("COALESCE(e.vendor, '(no platform)') as label, SUM(e.amount) as amount")->get()
            ->map(fn ($r) => ['label' => $r->label, 'amount' => $fmt($r->amount)])->all();

        return ['from' => $from, 'to' => $to, 'total' => $total, 'advertising' => $ads, 'by_category' => $byCat, 'by_month' => $byMonth, 'top_vendors' => $vendors, 'ad_platforms' => $adVendors];
    }

    /** Owner capital in, drawings out and net, plus loans (kept separate from equity and revenue). */
    public function ownerEquity(string $from, string $to): array
    {
        $sum = fn (string $table, string $col) => bcadd((string) DB::table($table)->where('tenant_id', $this->tid())->whereDate($col, '>=', $from)->whereDate($col, '<=', $to)->sum('amount'), '0', 2);
        $cap = $sum('capital_contributions', 'contribution_date');
        $draw = $sum('owner_drawings', 'drawing_date');
        $loans = bcadd((string) DB::table('loans')->where('tenant_id', $this->tid())->whereDate('received_date', '>=', $from)->whereDate('received_date', '<=', $to)->sum('principal'), '0', 2);

        return ['from' => $from, 'to' => $to, 'capital' => $cap, 'drawings' => $draw, 'net_owner_funding' => bcsub($cap, $draw, 2), 'loans_received' => $loans,
            'capital_balance' => $this->balanceOf(ChartOfAccounts::OWNER_CAPITAL), 'drawings_balance' => $this->balanceOf(ChartOfAccounts::OWNER_DRAWINGS),
            'loans_payable' => $this->balanceOf(ChartOfAccounts::LOANS_PAYABLE)];
    }

    /** Tax/VAT movements on the Tax payable account. A DRAFT for review by a qualified accountant. */
    public function taxSummary(string $from, string $to): array
    {
        ChartOfAccounts::ensure();
        $acct = Account::where('code', ChartOfAccounts::TAX_PAYABLE)->first();
        $opening = $this->balance($acct, $this->sums(null, date('Y-m-d', strtotime("$from -1 day")))[$acct->id] ?? null);
        $r = $this->sums($from, $to)[$acct->id] ?? null;
        $collected = bcadd((string) ($r->credit ?? 0), '0', 2);
        $adjusted = bcadd((string) ($r->debit ?? 0), '0', 2);
        $byMonth = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.tenant_id', $this->tid())->where('l.account_id', $acct->id)
            ->whereDate('e.transaction_date', '>=', $from)->whereDate('e.transaction_date', '<=', $to)->get(['e.transaction_date as d', 'l.credit', 'l.debit'])
            ->groupBy(fn ($x) => substr((string) $x->d, 0, 7))->map(fn ($g, $m) => ['label' => $m, 'amount' => bcsub((string) $g->sum('credit'), (string) $g->sum('debit'), 2)])->sortKeys()->values()->all();

        return ['from' => $from, 'to' => $to, 'opening' => $opening, 'collected' => $collected, 'adjusted' => $adjusted, 'closing' => bcsub(bcadd($opening, $collected, 2), $adjusted, 2), 'by_month' => $byMonth,
            'note' => 'Draft summary of tax collected on sales. Have a Bangladesh-qualified accountant review before filing.'];
    }
}
