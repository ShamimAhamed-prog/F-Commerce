<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingException;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\Poster;
use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\CapitalContribution;
use App\Models\FinancialAccount;
use App\Models\OwnerDrawing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Owner capital and drawings: equity movements, never revenue or expense. */
class EquityController extends Controller
{
    public function index(): Response
    {
        abort_unless(Gate::allows('accounting.view'), 403);
        ChartOfAccounts::ensure();
        $row = fn ($m, $dateCol) => ['id' => $m->id, 'date' => $m->{$dateCol}->toDateString(), 'amount' => (string) $m->amount, 'account' => $m->financialAccount->name, 'note' => $m->note];

        return Inertia::render('Accounting/Equity', [
            'contributions' => CapitalContribution::with('financialAccount:id,name')->latest('id')->limit(50)->get()->map(fn ($m) => $row($m, 'contribution_date')),
            'drawings' => OwnerDrawing::with('financialAccount:id,name')->latest('id')->limit(50)->get()->map(fn ($m) => $row($m, 'drawing_date')),
            'financial' => FinancialAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'today' => Ledger::today(),
            'canPost' => Gate::allows('accounting.post'),
        ]);
    }

    public function store(Request $request, string $kind, Poster $poster): RedirectResponse
    {
        abort_unless(Gate::allows('accounting.post') && in_array($kind, ['capital', 'drawing'], true), 403);
        ChartOfAccounts::ensure();
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999'],
            'financial_account_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        $model = $kind === 'capital' ? CapitalContribution::class : OwnerDrawing::class;
        if ($model::where('idempotency_key', $d['idempotency_key'])->exists()) {
            return back()->with('success', __('Already saved.'));
        }
        FinancialAccount::where('is_active', true)->findOrFail($d['financial_account_id']);

        try {
            DB::transaction(function () use ($d, $kind, $model, $poster, $request) {
                $m = $model::create([
                    ($kind === 'capital' ? 'contribution_date' : 'drawing_date') => $d['date'], 'amount' => $d['amount'],
                    'financial_account_id' => $d['financial_account_id'], 'note' => $d['note'] ?? null, 'idempotency_key' => $d['idempotency_key'], 'created_by' => $request->user()->id,
                ]);
                $m->load('financialAccount.account');
                $m->update(['entry_id' => ($kind === 'capital' ? $poster->capital($m) : $poster->drawing($m))->id]);
                Audit::record("accounting.{$kind}", $m, new: ['amount' => $m->amount]);
            });
        } catch (AccountingException $e) {
            throw ValidationException::withMessages(['date' => $e->getMessage()]);
        }

        return back()->with('success', __('Saved.'));
    }
}
