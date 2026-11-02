<?php

use App\Domain\Accounting\ChartOfAccounts as C;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\ReportService;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Loan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    [$this->user, $this->tenant] = memberOf();
    asTenant($this->tenant);
    $this->actingAs($this->user);
    $this->as = fn ($u = null) => $this->actingAs($u ?? $this->user)->withSession(['tenant_id' => $this->tenant->id]);
    C::ensure();
    $this->reports = app(ReportService::class);
    $this->bal = fn (string $c) => $this->reports->balanceOf($c);
    $this->cash = fn () => FinancialAccount::first();
    $this->loan = fn (array $o = []) => ($this->as)()->post('/app/accounting/loans', array_merge(['idempotency_key' => (string) Str::uuid(), 'lender' => 'City Bank', 'principal' => '50000',
        'received_date' => Ledger::today(), 'financial_account_id' => ($this->cash)()->id], $o));
});

it('records a loan as a liability, not income', function () {
    ($this->loan)()->assertSessionHasNoErrors();
    expect(($this->bal)(C::CASH))->toBe('50000.00')->and(($this->bal)(C::LOANS_PAYABLE))->toBe('50000.00');
    $pl = $this->reports->profitAndLoss(Ledger::today(), Ledger::today());
    expect($pl['total_income'])->toBe('0.00')->and($pl['net_profit'])->toBe('0.00');
    expect($this->reports->balanceSheet(Ledger::today())['balanced'])->toBeTrue();
});

it('splits repayments into principal (liability) and interest (expense)', function () {
    ($this->loan)();
    $loan = Loan::first();
    $repay = fn (array $o) => ($this->as)()->post("/app/accounting/loans/{$loan->id}/repay", array_merge(['idempotency_key' => (string) Str::uuid(), 'tx_date' => Ledger::today(), 'financial_account_id' => ($this->cash)()->id], $o));

    $repay(['principal' => '10000', 'interest' => '500'])->assertSessionHasNoErrors();
    expect(($this->bal)(C::LOANS_PAYABLE))->toBe('40000.00')->and(($this->bal)(C::INTEREST))->toBe('500.00')->and(($this->bal)(C::CASH))->toBe('39500.00');
    expect($this->reports->profitAndLoss(Ledger::today(), Ledger::today())['net_profit'])->toBe('-500.00'); // only the interest hits profit
    expect($loan->fresh()->outstanding())->toBe('40000.00');

    $repay(['principal' => '99999'])->assertSessionHasErrors('principal'); // cannot repay more than owed
    $repay([])->assertSessionHasErrors('principal');
    $repay(['principal' => '0', 'interest' => '100'])->assertSessionHasNoErrors(); // interest-only payment
    expect($this->reports->trialBalance(Ledger::today())['balanced'])->toBeTrue();
});

it('does not double-post a replayed loan or repayment, and needs accounting.post', function () {
    $key = (string) Str::uuid();
    ($this->loan)(['idempotency_key' => $key]);
    ($this->loan)(['idempotency_key' => $key]);
    expect(Loan::count())->toBe(1)->and(($this->bal)(C::LOANS_PAYABLE))->toBe('50000.00');

    [$viewer] = memberOf($this->tenant, 'viewer');
    ($this->as)($viewer)->post('/app/accounting/loans', ['idempotency_key' => (string) Str::uuid(), 'lender' => 'x', 'principal' => '1', 'received_date' => Ledger::today(), 'financial_account_id' => ($this->cash)()->id])->assertForbidden();
    ($this->as)($viewer)->get('/app/accounting/loans')->assertForbidden();
});

it('keeps loans inside the tenant', function () {
    ($this->loan)();
    $id = Loan::first()->id;
    [$u2, $t2] = memberOf();
    $this->actingAs($u2)->withSession(['tenant_id' => $t2->id])->post("/app/accounting/loans/{$id}/repay", ['idempotency_key' => (string) Str::uuid(), 'tx_date' => Ledger::today(), 'principal' => '1', 'financial_account_id' => 1])->assertNotFound();
});

// ---------------------------------------------------------------- receipts
it('stores an expense receipt privately under a random name and serves it to authorised users only', function () {
    $cat = ExpenseCategory::first();
    $img = UploadedFile::fake()->image('IMG_1234.jpg', 400, 300);
    ($this->as)()->post('/app/accounting/expenses', ['idempotency_key' => (string) Str::uuid(), 'expense_date' => Ledger::today(), 'expense_category_id' => $cat->id, 'amount' => '120', 'financial_account_id' => ($this->cash)()->id, 'receipt' => $img])->assertSessionHasNoErrors();

    $e = Expense::first();
    expect($e->receipt_path)->toStartWith("tenants/{$this->tenant->id}/receipts/")->and($e->receipt_path)->not->toContain('IMG_1234')->and($e->receipt_mime)->toBe('image/jpeg');
    Storage::disk('local')->assertExists($e->receipt_path);

    $res = ($this->as)()->get("/app/accounting/expenses/{$e->id}/receipt")->assertOk();
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($res->headers->get('Content-Security-Policy'))->toContain('sandbox');

    [$u2, $t2] = memberOf();
    $this->actingAs($u2)->withSession(['tenant_id' => $t2->id])->get("/app/accounting/expenses/{$e->id}/receipt")->assertNotFound();
    [$viewer] = memberOf($this->tenant, 'viewer');
    ($this->as)($viewer)->get("/app/accounting/expenses/{$e->id}/receipt")->assertForbidden(); // viewer lacks expenses.view
});

it('rejects receipts that are not images or PDFs, by content', function () {
    $cat = ExpenseCategory::first();
    $post = fn ($file) => ($this->as)()->post('/app/accounting/expenses', ['idempotency_key' => (string) Str::uuid(), 'expense_date' => Ledger::today(), 'expense_category_id' => $cat->id, 'amount' => '5', 'financial_account_id' => ($this->cash)()->id, 'receipt' => $file]);
    $post(UploadedFile::fake()->createWithContent('r.jpg', '<?php system($_GET[0]); ?>'))->assertSessionHasErrors('receipt');
    $post(UploadedFile::fake()->createWithContent('r.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'))->assertSessionHasErrors('receipt');
    $post(UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\n%fake\n"))->assertSessionHasNoErrors(); // real PDF header accepted
    expect(Expense::count())->toBe(1)->and(count(Storage::disk('local')->allFiles()))->toBe(1);
});

it('does not keep the receipt when the expense is rejected (closed period)', function () {
    app(Ledger::class)->closePeriod(Ledger::today());
    $cat = ExpenseCategory::first();
    ($this->as)()->post('/app/accounting/expenses', ['idempotency_key' => (string) Str::uuid(), 'expense_date' => Ledger::today(), 'expense_category_id' => $cat->id, 'amount' => '5', 'financial_account_id' => ($this->cash)()->id, 'receipt' => UploadedFile::fake()->image('a.png')])->assertSessionHasErrors('expense_date');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

// ------------------------------------------------------------- reports & CSV
it('summarises expenses with advertising called out, owner equity and tax', function () {
    $ads = ExpenseCategory::where('name', 'Advertising / marketing')->first();
    $rent = ExpenseCategory::where('name', 'Rent')->first();
    $add = fn ($cat, $amt, $vendor = null) => ($this->as)()->post('/app/accounting/expenses', ['idempotency_key' => (string) Str::uuid(), 'expense_date' => Ledger::today(), 'expense_category_id' => $cat->id, 'amount' => $amt, 'financial_account_id' => ($this->cash)()->id, 'vendor' => $vendor]);
    $add($ads, '5000', 'Meta'); $add($ads, '1500', 'TikTok'); $add($rent, '12000', 'Landlord');
    ($this->as)()->post('/app/accounting/equity/capital', ['idempotency_key' => (string) Str::uuid(), 'date' => Ledger::today(), 'amount' => '70000', 'financial_account_id' => ($this->cash)()->id]);
    ($this->as)()->post('/app/accounting/equity/drawing', ['idempotency_key' => (string) Str::uuid(), 'date' => Ledger::today(), 'amount' => '8000', 'financial_account_id' => ($this->cash)()->id]);
    ($this->loan)();

    $t = Ledger::today();
    $ex = $this->reports->expenseSummary($t, $t);
    expect($ex['total'])->toBe('18500.00')->and($ex['advertising'])->toBe('6500.00')->and($ex['by_category'][0])->toBe(['label' => 'Rent', 'amount' => '12000.00', 'count' => 1])
        ->and(collect($ex['ad_platforms'])->pluck('amount', 'label')->all())->toBe(['Meta' => '5000.00', 'TikTok' => '1500.00']);

    $eq = $this->reports->ownerEquity($t, $t);
    expect($eq['capital'])->toBe('70000.00')->and($eq['drawings'])->toBe('8000.00')->and($eq['net_owner_funding'])->toBe('62000.00')->and($eq['loans_received'])->toBe('50000.00')->and($eq['loans_payable'])->toBe('50000.00');

    foreach (['expense-summary', 'owner-equity', 'tax-summary'] as $type) {
        ($this->as)()->get("/app/reports/{$type}")->assertOk()->assertInertia(fn ($a) => $a->component('Reports/Show')->where('type', $type));
    }
});

it('reports tax collected on shipped orders, reconciling to the tax payable account', function () {
    $p = App\Models\Product::factory()->create(['selling_price' => '1000']);
    app(App\Domain\Inventory\StockService::class)->record($p->id, null, 'opening', 5, '400');
    $svc = app(App\Domain\Sales\OrderService::class);
    $o = $svc->create(['customer_name' => 'A', 'phone' => '01712345678', 'address' => 'X', 'tax_amount' => '150', 'items' => [['product_id' => $p->id, 'qty' => 1]]]);
    foreach (['confirmed', 'processing', 'ready_to_ship', 'shipped'] as $s) {
        $svc->transition($o, $s);
    }
    $tax = $this->reports->taxSummary(Ledger::today(), Ledger::today());
    expect($tax['collected'])->toBe('150.00')->and($tax['closing'])->toBe(($this->bal)(C::TAX_PAYABLE))->and($tax['note'])->toContain('accountant');
});

it('exports every report as CSV with formula-injection protection and respects permissions', function () {
    $cat = ExpenseCategory::first();
    ($this->as)()->post('/app/accounting/expenses', ['idempotency_key' => (string) Str::uuid(), 'expense_date' => Ledger::today(), 'expense_category_id' => $cat->id, 'amount' => '10', 'financial_account_id' => ($this->cash)()->id, 'vendor' => '=HYPERLINK("http://evil")']);

    foreach (['trial-balance', 'profit-loss', 'balance-sheet', 'cash-flow', 'ledger', 'expense-summary', 'owner-equity', 'tax-summary'] as $type) {
        $res = ($this->as)()->get("/app/reports/{$type}?format=csv")->assertOk();
        expect($res->headers->get('Content-Type'))->toContain('text/csv')->and($res->headers->get('Content-Disposition'))->toContain("{$type}_");
        expect(ltrim($res->streamedContent(), "\xEF\xBB\xBF"))->not->toBe('');
    }
    $csv = ($this->as)()->get('/app/reports/expense-summary?format=csv')->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->and($csv)->not->toMatch('/(^|\n),?"?=HYPERLINK/');
    expect(ltrim(($this->as)()->get('/app/reports/trial-balance?format=csv')->streamedContent(), "\xEF\xBB\xBF"))->toStartWith('Code,Account,Type,Debit,Credit');

    [$mgr] = memberOf($this->tenant, 'manager');
    ($this->as)($mgr)->get('/app/reports/trial-balance?format=csv')->assertForbidden();
    [$accts] = memberOf($this->tenant, 'accounts_staff');
    ($this->as)($accts)->get('/app/reports/profit-loss?format=csv')->assertOk(); // accounts staff may see profit
});
