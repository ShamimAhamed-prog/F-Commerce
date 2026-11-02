<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingException;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\ReportService;
use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountingController extends Controller
{
    public function __construct(private ReportService $reports, private Ledger $ledger) {}

    private function view(): void
    {
        abort_unless(Gate::allows('accounting.view'), 403);
        ChartOfAccounts::ensure();
    }

    public function overview(): Response
    {
        $this->view();
        $today = Ledger::today();
        $monthStart = Carbon::parse($today)->startOfMonth()->toDateString();

        $fin = FinancialAccount::with('account')->where('is_active', true)->get()->map(fn ($f) => [
            'id' => $f->id, 'name' => $f->name, 'type' => $f->type, 'balance' => $this->reports->balanceOf($f->account->code),
        ]);
        $showProfit = Gate::allows('profit.view');
        $pl = $showProfit ? $this->reports->profitAndLoss($monthStart, $today) : null;
        $stockValue = (string) DB::table('stock_balances')->where('tenant_id', app(\App\Domain\Tenancy\CurrentTenant::class)->id())
            ->selectRaw('COALESCE(SUM(on_hand * avg_cost),0) as v')->value('v');

        return Inertia::render('Accounting/Overview', [
            'cash_total' => $fin->reduce(fn ($a, $f) => bcadd($a, $f['balance'], 2), '0.00'),
            'financial_accounts' => $fin,
            'cod_receivable' => $this->reports->balanceOf(ChartOfAccounts::COD_RECEIVABLE),
            'inventory' => ['ledger' => $this->reports->balanceOf(ChartOfAccounts::INVENTORY), 'stock' => number_format((float) $stockValue, 2, '.', '')],
            'month' => $pl ? ['from' => $monthStart, 'to' => $today, 'income' => $pl['total_income'], 'cogs' => $pl['total_cogs'], 'expenses' => $pl['total_expenses'], 'net' => $pl['net_profit']] : null,
            'locked_through' => Ledger::lockedThrough(),
            'recent' => JournalEntry::latest('id')->limit(6)->get(['id', 'entry_number', 'transaction_date', 'memo', 'source_type']),
            'canPost' => Gate::allows('accounting.post'),
        ]);
    }

    public function accounts(): Response
    {
        $this->view();
        $sums = DB::table('journal_entry_lines')->where('tenant_id', app(\App\Domain\Tenancy\CurrentTenant::class)->id())
            ->groupBy('account_id')->selectRaw('account_id, SUM(debit) d, SUM(credit) c')->get()->keyBy('account_id');

        return Inertia::render('Accounting/Accounts', [
            'accounts' => Account::orderBy('code')->get()->map(function ($a) use ($sums) {
                $r = $sums[$a->id] ?? null;
                $bal = $r ? ($a->isDebitNormal() ? bcsub((string) $r->d, (string) $r->c, 2) : bcsub((string) $r->c, (string) $r->d, 2)) : '0.00';

                return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'balance' => $bal];
            }),
            'financial' => FinancialAccount::with('account:id,code')->get()->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'type' => $f->type, 'code' => $f->account->code]),
            'locked_through' => Ledger::lockedThrough(),
            'today' => Ledger::today(),
            'canPost' => Gate::allows('accounting.post'),
            'canClose' => Gate::allows('accounting.period_close'),
        ]);
    }

    /** Cash / bank / MFS accounts each get their own asset account in the chart. */
    public function addFinancialAccount(Request $request): RedirectResponse
    {
        abort_unless(Gate::allows('accounting.post'), 403);
        ChartOfAccounts::ensure();
        $d = $request->validate(['name' => ['required', 'string', 'max:100'], 'type' => ['required', Rule::in(['cash', 'bank', 'mfs'])]]);
        if (FinancialAccount::where('name', $d['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'An account with this name already exists.']);
        }
        $code = null;
        for ($n = 1020; $n < 1100; $n++) {
            if (! Account::where('code', (string) $n)->exists()) {
                $code = (string) $n;
                break;
            }
        }
        $code ?? throw ValidationException::withMessages(['name' => 'No free account codes left.']);

        $acct = Account::create(['code' => $code, 'name' => $d['name'], 'type' => 'asset', 'is_system' => false]);
        $fa = FinancialAccount::create(['account_id' => $acct->id, 'name' => $d['name'], 'type' => $d['type']]);
        Audit::record('accounting.financial_account_added', $fa, new: $d + ['code' => $code]);

        return back()->with('success', __('Account added.'));
    }

    public function closePeriod(Request $request): RedirectResponse
    {
        abort_unless(Gate::allows('accounting.period_close'), 403);
        $d = $request->validate(['through' => ['required', 'date_format:Y-m-d']]);
        try {
            $this->ledger->closePeriod($d['through']);
        } catch (AccountingException $e) {
            throw ValidationException::withMessages(['through' => $e->getMessage()]);
        }

        return back()->with('success', __('Period closed through :d.', ['d' => $d['through']]));
    }

    public function journal(Request $request): Response
    {
        $this->view();
        $page = JournalEntry::with(['lines.account:id,code,name'])->orderByDesc('id')
            ->when($request->input('source'), fn ($q, $s) => $q->where('source_type', $s))
            ->paginate(25)->withQueryString();
        $reversed = JournalEntry::whereIn('reverses_entry_id', $page->pluck('id'))->pluck('reverses_entry_id')->all();

        return Inertia::render('Accounting/Journal', [
            'entries' => $page->through(fn ($e) => [
                'id' => $e->id, 'entry_number' => $e->entry_number, 'date' => $e->transaction_date->toDateString(), 'memo' => $e->memo, 'source_type' => $e->source_type,
                'reversed' => in_array($e->id, $reversed), 'is_reversal' => (bool) $e->reverses_entry_id,
                'lines' => $e->lines->map(fn ($l) => ['account' => "{$l->account->code} {$l->account->name}", 'debit' => (string) $l->debit, 'credit' => (string) $l->credit, 'description' => $l->description]),
            ]),
            'filters' => $request->only('source'),
            'canPost' => Gate::allows('accounting.post'),
        ]);
    }

    /** The only way to correct a posted entry: post its mirror image, keeping the original. */
    public function reverse(Request $request, JournalEntry $entry): RedirectResponse
    {
        abort_unless(Gate::allows('accounting.post'), 403);
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        if ($entry->reverses_entry_id || $this->ledger->isReversed($entry)) {
            throw ValidationException::withMessages(['reason' => 'This entry is a reversal or has already been reversed.']);
        }
        try {
            $this->ledger->reverse($entry, "Reversal of {$entry->entry_number}: {$d['reason']}");
        } catch (AccountingException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', __('Entry reversed.'));
    }
}
