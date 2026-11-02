<?php

use App\Domain\Accounting\AccountingException;
use App\Domain\Accounting\ChartOfAccounts as C;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\ReportService;
use App\Domain\Inventory\StockService;
use App\Domain\Sales\OrderService;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CodSettlement;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    [$this->user, $this->tenant] = memberOf();
    asTenant($this->tenant);
    $this->actingAs($this->user);
    $this->as = fn ($u = null) => $this->actingAs($u ?? $this->user)->withSession(['tenant_id' => $this->tenant->id]);
    $this->ledger = app(Ledger::class);
    $this->reports = app(ReportService::class);
    $this->stock = app(StockService::class);
    $this->cash = fn () => FinancialAccount::first();
    $bal = fn (string $code) => $this->reports->balanceOf($code);
    $this->bal = $bal;

    /** product costing 900 selling 2000, 10 units; order of 1 unit with 80 delivery and 100 advance (spec example C) */
    $this->p = Product::factory()->create(['sku' => 'MUG', 'selling_price' => '2000', 'purchase_cost' => '900']);
    $this->stock->record($this->p->id, null, 'opening', 10, '900');
    $this->ship = function (int $qty = 1, string $advance = '100', string $shipping = '80') {
        $svc = app(OrderService::class);
        $o = $svc->create(['customer_name' => 'Rahim', 'phone' => '01712345678', 'address' => 'Dhaka', 'shipping_charge' => $shipping, 'advance' => $advance,
            'items' => [['product_id' => $this->p->id, 'qty' => $qty]]], (string) Str::uuid());
        foreach (['confirmed', 'processing', 'ready_to_ship', 'shipped'] as $s) {
            $svc->transition($o, $s);
        }

        return $o->refresh();
    };
});

// ---------------------------------------------------------------- ledger integrity
it('rejects unbalanced, one-sided, single-line and invalid entries', function () {
    $bad = [
        [['account' => C::CASH, 'debit' => '100'], ['account' => C::SALES, 'credit' => '90']],
        [['account' => C::CASH, 'debit' => '100', 'credit' => '100'], ['account' => C::SALES, 'credit' => '0']],
        [['account' => C::CASH, 'debit' => '100']],
        [['account' => C::CASH, 'debit' => '10.001'], ['account' => C::SALES, 'credit' => '10.001']],
        [['account' => '9999', 'debit' => '10'], ['account' => C::SALES, 'credit' => '10']],
    ];
    foreach ($bad as $lines) {
        expect(fn () => $this->ledger->post(['memo' => 'x', 'lines' => $lines]))->toThrow(AccountingException::class);
    }
    expect(JournalEntry::where('memo', 'x')->count())->toBe(0);
});

it('makes posted entries and lines immutable, and posting is idempotent per source', function () {
    $e = $this->ledger->post(['memo' => 'm', 'source_key' => 'k1', 'lines' => [['account' => C::CASH, 'debit' => '5'], ['account' => C::OWNER_CAPITAL, 'credit' => '5']]]);
    expect(fn () => $e->update(['memo' => 'z']))->toThrow(LogicException::class);
    expect(fn () => $e->delete())->toThrow(LogicException::class);
    expect(fn () => $e->lines()->first()->update(['debit' => '9']))->toThrow(LogicException::class);
    expect(fn () => JournalEntryLine::first()->delete())->toThrow(LogicException::class);

    $again = $this->ledger->post(['memo' => 'm', 'source_key' => 'k1', 'lines' => [['account' => C::CASH, 'debit' => '5'], ['account' => C::OWNER_CAPITAL, 'credit' => '5']]]);
    expect($again->id)->toBe($e->id)->and(JournalEntry::where('source_key', 'k1')->count())->toBe(1);
});

it('reverses with a mirrored entry, keeps the original, and only once', function () {
    $e = $this->ledger->post(['memo' => 'm', 'lines' => [['account' => C::CASH, 'debit' => '50'], ['account' => C::OWNER_CAPITAL, 'credit' => '50']]]);
    $rev = $this->ledger->reverse($e, 'oops');
    expect($rev->reverses_entry_id)->toBe($e->id)->and(($this->bal)(C::CASH))->toBe('0.00');
    expect(JournalEntry::find($e->id))->not->toBeNull()->and($this->ledger->reverse($e, 'again')->id)->toBe($rev->id);
    expect(JournalEntry::whereIn('id', [$e->id, $rev->id])->count())->toBe(2)->and(JournalEntry::where('reverses_entry_id', $e->id)->count())->toBe(1);
});

it('blocks posting into a closed period and cannot close the future', function () {
    $today = Ledger::today();
    $this->ledger->closePeriod($today);
    expect(fn () => $this->ledger->post(['memo' => 'late', 'date' => $today, 'lines' => [['account' => C::CASH, 'debit' => '5'], ['account' => C::OWNER_CAPITAL, 'credit' => '5']]]))
        ->toThrow(AccountingException::class, 'closed');
    expect(fn () => $this->ledger->closePeriod(date('Y-m-d', strtotime('+3 days'))))->toThrow(AccountingException::class);
    expect(fn () => $this->ledger->closePeriod(date('Y-m-d', strtotime('-3 days'))))->toThrow(AccountingException::class, 'already closed');
});

// -------------------------------------------------------------------- sales & COGS
it('posts the spec example: sale 2000 + delivery 80 + advance 100, cost 900', function () {
    $o = ($this->ship)();
    expect((string) $o->total)->toBe('2080.00');

    // Revenue entry: Dr Cash 100, Dr COD receivable 1980 / Cr Sales 2000, Cr Delivery income 80
    expect(($this->bal)(C::CASH))->toBe('100.00')->and(($this->bal)(C::COD_RECEIVABLE))->toBe('1980.00')
        ->and(($this->bal)(C::SALES))->toBe('2000.00')->and(($this->bal)(C::DELIVERY_INCOME))->toBe('80.00');
    // COGS recognised on sale, from inventory, at the weighted-average cost
    expect(($this->bal)(C::COGS))->toBe('900.00')->and(($this->bal)(C::INVENTORY))->toBe('8100.00'); // 9000 - 900
    expect(JournalEntry::whereIn('source_key', ["order:{$o->id}:revenue", "order:{$o->id}:cogs"])->count())->toBe(2);
    expect($this->reports->trialBalance(Ledger::today())['balanced'])->toBeTrue();
});

it('applies discounts as a contra-revenue account and keeps the entry balanced', function () {
    $svc = app(OrderService::class);
    $o = $svc->create(['customer_name' => 'A', 'phone' => '01712345678', 'address' => 'X', 'order_discount' => '200', 'items' => [['product_id' => $this->p->id, 'qty' => 1, 'discount' => '100']]]);
    foreach (['confirmed', 'processing', 'ready_to_ship', 'shipped'] as $s) {
        $svc->transition($o, $s);
    }
    expect(($this->bal)(C::SALES_DISCOUNTS))->toBe('-300.00') // contra income carries a negative balance
        ->and($this->reports->profitAndLoss(Ledger::today(), Ledger::today())['total_income'])->toBe('1700.00');
    expect($this->reports->trialBalance(Ledger::today())['balanced'])->toBeTrue();
});

it('recognises COGS on sale, not on purchase of inventory', function () {
    $bank = FinancialAccount::create(['account_id' => Account::create(['code' => '1020', 'name' => 'City Bank', 'type' => 'asset'])->id, 'name' => 'City Bank', 'type' => 'bank']);
    $this->capital = fn ($amt) => ($this->as)()->post('/app/accounting/equity/capital', ['idempotency_key' => (string) Str::uuid(), 'date' => Ledger::today(), 'amount' => $amt, 'financial_account_id' => $bank->id]);
    ($this->capital)('50000')->assertSessionHasNoErrors();
    ($this->as)()->post('/app/inventory/adjust', ['product_id' => $this->p->id, 'mode' => 'delta', 'quantity' => 20, 'reason' => 'Purchase', 'unit_cost' => '900', 'paid_from' => $bank->id])->assertSessionHasNoErrors();

    expect(($this->bal)('1020'))->toBe('32000.00')               // 50,000 - 18,000
        ->and(($this->bal)(C::INVENTORY))->toBe('27000.00')      // 9,000 opening + 18,000
        ->and(($this->bal)(C::COGS))->toBe('0.00');              // a purchase is not COGS
    expect($this->reports->profitAndLoss(Ledger::today(), Ledger::today())['net_profit'])->toBe('0.00');
});

it('keeps the inventory asset equal to stock valuation', function () {
    $this->stock->record($this->p->id, null, 'adjustment', -2, null, 'damaged');
    ($this->ship)(3);
    $valuation = (string) DB::table('stock_balances')->selectRaw('SUM(on_hand * avg_cost) v')->value('v');
    expect(($this->bal)(C::INVENTORY))->toBe(number_format((float) $valuation, 2, '.', ''))->and(($this->bal)(C::INVENTORY))->toBe('4500.00'); // 5 units x 900
});

// -------------------------------------------------------- delivery vs COD settlement
it('does not treat delivery as COD payment; settlement clears the receivable, fee booked once', function () {
    $o = ($this->ship)();
    app(OrderService::class)->transition($o, 'delivered');
    expect(($this->bal)(C::COD_RECEIVABLE))->toBe('1980.00'); // delivered, still owed

    $bank = FinancialAccount::create(['account_id' => Account::create(['code' => '1020', 'name' => 'Bank', 'type' => 'asset'])->id, 'name' => 'Bank', 'type' => 'bank']);
    $settle = fn ($received, $fee = '0') => ($this->as)()->post('/app/accounting/cod', ['idempotency_key' => (string) Str::uuid(), 'order_id' => $o->id,
        'settled_date' => Ledger::today(), 'received' => $received, 'fee' => $fee, 'financial_account_id' => $bank->id]);

    $settle('1000', '40')->assertSessionHasNoErrors();           // partial: 1040 cleared
    expect(($this->bal)(C::COD_RECEIVABLE))->toBe('940.00')->and(($this->bal)(C::COURIER_EXPENSE))->toBe('40.00')->and(($this->bal)('1020'))->toBe('1000.00');
    $settle('5000')->assertSessionHasErrors('received');         // cannot over-settle
    $settle('900', '40')->assertSessionHasNoErrors();            // remainder
    expect(($this->bal)(C::COD_RECEIVABLE))->toBe('0.00')->and(($this->bal)(C::COURIER_EXPENSE))->toBe('80.00')
        ->and($this->reports->profitAndLoss(Ledger::today(), Ledger::today())['total_income'])->toBe('2080.00'); // settlement is not new revenue
    expect(CodSettlement::count())->toBe(2)->and($this->reports->codReceivables())->toBe([]);
});

it('only settles delivered orders and replays of the same submit do not double-post', function () {
    $o = ($this->ship)();
    $key = (string) Str::uuid();
    $body = ['idempotency_key' => $key, 'order_id' => $o->id, 'settled_date' => Ledger::today(), 'received' => '500', 'financial_account_id' => ($this->cash)()->id];
    ($this->as)()->post('/app/accounting/cod', $body)->assertSessionHasErrors('order_id'); // still only shipped
    app(OrderService::class)->transition($o, 'delivered');
    ($this->as)()->post('/app/accounting/cod', $body);
    ($this->as)()->post('/app/accounting/cod', $body); // same key
    expect(CodSettlement::count())->toBe(1)->and(($this->bal)(C::COD_RECEIVABLE))->toBe('1480.00');
});

// ------------------------------------------------------------------- returns
it('reverses revenue (keeping the original) on return, leaves COGS until restock, and blocks if COD was settled', function () {
    $o = ($this->ship)();
    $svc = app(OrderService::class);
    foreach (['delivery_failed', 'return_requested', 'returned'] as $s) {
        $svc->transition($o, $s);
    }
    expect(($this->bal)(C::SALES))->toBe('0.00')->and(($this->bal)(C::COD_RECEIVABLE))->toBe('0.00')->and(($this->bal)(C::COGS))->toBe('900.00');
    expect(JournalEntry::where('source_key', "order:{$o->id}:revenue")->exists())->toBeTrue()->and(JournalEntry::where('source_type', 'reversal')->count())->toBe(1);

    $o2 = ($this->ship)();
    $svc->transition($o2, 'delivered');
    ($this->as)()->post('/app/accounting/cod', ['idempotency_key' => (string) Str::uuid(), 'order_id' => $o2->id, 'settled_date' => Ledger::today(), 'received' => '100', 'financial_account_id' => ($this->cash)()->id]);
    $svc->transition($o2, 'return_requested');
    expect(fn () => $svc->transition($o2, 'returned'))->toThrow(Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------- equity, loans and expenses vs revenue
it('treats owner capital and drawings as equity, not income or expense', function () {
    ($this->as)()->post('/app/accounting/equity/capital', ['idempotency_key' => (string) Str::uuid(), 'date' => Ledger::today(), 'amount' => '100000', 'financial_account_id' => ($this->cash)()->id])->assertSessionHasNoErrors();
    ($this->as)()->post('/app/accounting/equity/drawing', ['idempotency_key' => (string) Str::uuid(), 'date' => Ledger::today(), 'amount' => '15000', 'financial_account_id' => ($this->cash)()->id])->assertSessionHasNoErrors();

    $pl = $this->reports->profitAndLoss(Ledger::today(), Ledger::today());
    expect(($this->bal)(C::CASH))->toBe('85000.00')->and(($this->bal)(C::OWNER_CAPITAL))->toBe('100000.00')->and(($this->bal)(C::OWNER_DRAWINGS))->toBe('-15000.00') // contra-equity: reduces owner equity
        ->and($pl['total_income'])->toBe('0.00')->and($pl['total_expenses'])->toBe('0.00')->and($pl['net_profit'])->toBe('0.00');
});

it('expenses reduce cash and profit; void reverses and keeps the original; replays do not duplicate', function () {
    $cat = App\Models\ExpenseCategory::where('name', 'Advertising / marketing')->first();
    $body = fn ($key) => ['idempotency_key' => $key, 'expense_date' => Ledger::today(), 'expense_category_id' => $cat->id, 'amount' => '5000', 'financial_account_id' => ($this->cash)()->id, 'vendor' => 'Meta'];
    $key = (string) Str::uuid();
    ($this->as)()->post('/app/accounting/expenses', $body($key))->assertSessionHasNoErrors();
    ($this->as)()->post('/app/accounting/expenses', $body($key)); // replay
    expect(Expense::count())->toBe(1)->and(($this->bal)(C::ADVERTISING))->toBe('5000.00')->and(($this->bal)(C::CASH))->toBe('-5000.00')
        ->and($this->reports->profitAndLoss(Ledger::today(), Ledger::today())['net_profit'])->toBe('-5000.00');

    $e = Expense::first();
    ($this->as)()->post("/app/accounting/expenses/{$e->id}/void")->assertSessionHasNoErrors();
    expect($e->fresh()->status)->toBe('void')->and(($this->bal)(C::ADVERTISING))->toBe('0.00')->and(JournalEntry::count())->toBeGreaterThanOrEqual(3); // original + reversal kept
    ($this->as)()->post("/app/accounting/expenses/{$e->id}/void")->assertStatus(409);
});

it('blocks expenses dated in a closed period and in the future', function () {
    $cat = App\Models\ExpenseCategory::first();
    $this->ledger->closePeriod(Ledger::today());
    $post = fn ($date) => ($this->as)()->post('/app/accounting/expenses', ['idempotency_key' => (string) Str::uuid(), 'expense_date' => $date, 'expense_category_id' => $cat->id, 'amount' => '10', 'financial_account_id' => ($this->cash)()->id]);
    $post(Ledger::today())->assertSessionHasErrors('expense_date');
    $post(date('Y-m-d', strtotime('+2 days')))->assertSessionHasErrors('expense_date');
    expect(Expense::count())->toBe(0);
});

// ------------------------------------------------------------------- reports
it('balance sheet balances and reports reconcile to the general ledger', function () {
    ($this->as)()->post('/app/accounting/equity/capital', ['idempotency_key' => (string) Str::uuid(), 'date' => Ledger::today(), 'amount' => '20000', 'financial_account_id' => ($this->cash)()->id]);
    $o = ($this->ship)(2);
    app(OrderService::class)->transition($o, 'delivered');
    $cat = App\Models\ExpenseCategory::where('name', 'Rent')->first();
    ($this->as)()->post('/app/accounting/expenses', ['idempotency_key' => (string) Str::uuid(), 'expense_date' => Ledger::today(), 'expense_category_id' => $cat->id, 'amount' => '3000', 'financial_account_id' => ($this->cash)()->id]);

    $today = Ledger::today();
    $bs = $this->reports->balanceSheet($today);
    expect($bs['balanced'])->toBeTrue()->and($this->reports->trialBalance($today)['balanced'])->toBeTrue();

    $pl = $this->reports->profitAndLoss($today, $today);
    // revenue 4000 + 80, cogs 1800, rent 3000
    expect($pl['total_income'])->toBe('4080.00')->and($pl['total_cogs'])->toBe('1800.00')->and($pl['gross_profit'])->toBe('2280.00')->and($pl['net_profit'])->toBe('-720.00');
    // equity on the balance sheet = capital + current earnings
    expect($bs['total_equity'])->toBe('28280.00'); // capital 20000 + opening-balance equity 9000 (offsets opening stock) - 720 loss

    $cash = Account::where('code', C::CASH)->first();
    $gl = $this->reports->ledger($cash, $today, $today);
    expect($gl['closing'])->toBe(($this->bal)(C::CASH))->and(collect($bs['assets'])->firstWhere('code', C::CASH)['amount'])->toBe($gl['closing']);

    $cf = $this->reports->cashFlow($today, $today);
    expect($cf['closing'])->toBe(($this->bal)(C::CASH))->and($cf['opening'])->toBe('0.00');
});

it('lists customer-facing report pages and gates profit reports by profit.view', function () {
    ($this->ship)();
    foreach (['trial-balance', 'profit-loss', 'balance-sheet', 'cash-flow', 'ledger'] as $t) {
        ($this->as)()->get("/app/reports/{$t}")->assertOk()->assertInertia(fn ($a) => $a->component('Reports/Show')->where('type', $t));
    }
    ($this->as)()->get('/app/reports/nope')->assertNotFound();
    ($this->as)()->get('/app/reports/ledger?from=2026-12-01&to=2026-01-01')->assertStatus(422);

    [$mgr] = memberOf($this->tenant, 'manager'); // reports.view but no accounting/profit access
    ($this->as)($mgr)->get('/app/reports/profit-loss')->assertForbidden();
    ($this->as)($mgr)->get('/app/reports/trial-balance')->assertForbidden();
    ($this->as)($mgr)->get('/app/accounting')->assertForbidden();
});

// ---------------------------------------------------------- permissions & isolation
it('enforces accounting permissions per role', function () {
    [$accts] = memberOf($this->tenant, 'accounts_staff');
    [$viewer] = memberOf($this->tenant, 'viewer');
    ($this->as)($accts)->get('/app/accounting')->assertOk();
    ($this->as)($accts)->get('/app/accounting/journal')->assertOk();
    ($this->as)($accts)->post('/app/accounting/close-period', ['through' => Ledger::today()])->assertForbidden(); // period close is owner-only
    ($this->as)($viewer)->get('/app/accounting')->assertForbidden();
    ($this->as)($viewer)->post('/app/accounting/equity/capital', ['idempotency_key' => (string) Str::uuid(), 'date' => Ledger::today(), 'amount' => '1', 'financial_account_id' => ($this->cash)()->id])->assertForbidden();
    ($this->as)()->post('/app/accounting/close-period', ['through' => Ledger::today()])->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'accounting.period_closed')->exists())->toBeTrue();
});

it('reverses any entry from the journal with a reason, once', function () {
    $e = $this->ledger->post(['memo' => 'Manual', 'lines' => [['account' => C::CASH, 'debit' => '70'], ['account' => C::OWNER_CAPITAL, 'credit' => '70']]]);
    ($this->as)()->post("/app/accounting/journal/{$e->id}/reverse", ['reason' => 'x'])->assertSessionHasErrors('reason'); // too short
    ($this->as)()->post("/app/accounting/journal/{$e->id}/reverse", ['reason' => 'Entered twice'])->assertSessionHasNoErrors();
    ($this->as)()->post("/app/accounting/journal/{$e->id}/reverse", ['reason' => 'Entered twice'])->assertSessionHasErrors('reason');
    expect(($this->bal)(C::CASH))->toBe('0.00');
});

it('keeps each tenant ledger separate with its own chart and numbering', function () {
    ($this->ship)();
    $entryId = JournalEntry::first()->id;
    [$u2, $t2] = memberOf();
    $b = fn () => $this->actingAs($u2)->withSession(['tenant_id' => $t2->id]);
    $b()->get('/app/accounting')->assertInertia(fn ($a) => $a->where('cod_receivable', '0.00')->where('cash_total', '0.00'));
    $b()->post("/app/accounting/journal/{$entryId}/reverse", ['reason' => 'steal it'])->assertNotFound();
    asTenant($t2);
    expect(JournalEntry::count())->toBe(0)->and(Account::count())->toBe(count(C::DEFAULTS))->and(FinancialAccount::count())->toBe(1);
    asTenant($this->tenant);
    expect(JournalEntry::first()->entry_number)->toBe('JE-000001');
});

it('adds financial accounts with their own ledger account', function () {
    ($this->as)()->post('/app/accounting/financial-accounts', ['name' => 'bKash Personal', 'type' => 'mfs'])->assertSessionHasNoErrors();
    ($this->as)()->post('/app/accounting/financial-accounts', ['name' => 'bKash Personal', 'type' => 'mfs'])->assertSessionHasErrors('name');
    $fa = FinancialAccount::where('name', 'bKash Personal')->first();
    expect($fa->account->code)->toBe('1020')->and($fa->account->type)->toBe('asset');
});
