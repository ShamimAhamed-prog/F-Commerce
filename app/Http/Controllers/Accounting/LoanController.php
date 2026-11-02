<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingException;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\Poster;
use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\Loan;
use App\Models\LoanTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Borrowed money is a LIABILITY (Loans payable), not income; repaid interest is an expense. */
class LoanController extends Controller
{
    public function index(): Response
    {
        abort_unless(Gate::allows('accounting.view'), 403);
        ChartOfAccounts::ensure();

        return Inertia::render('Accounting/Loans', [
            'loans' => Loan::with(['financialAccount:id,name', 'transactions'])->latest('id')->get()->map(fn ($l) => [
                'id' => $l->id, 'lender' => $l->lender, 'principal' => (string) $l->principal, 'received_date' => $l->received_date->toDateString(), 'account' => $l->financialAccount->name,
                'outstanding' => $l->outstanding(), 'note' => $l->note,
                'repayments' => $l->transactions->map(fn ($t) => ['id' => $t->id, 'date' => $t->tx_date->toDateString(), 'principal' => (string) $t->principal, 'interest' => (string) $t->interest]),
            ]),
            'financial' => FinancialAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'today' => Ledger::today(),
            'canPost' => Gate::allows('accounting.post'),
        ]);
    }

    public function store(Request $request, Poster $poster): RedirectResponse
    {
        abort_unless(Gate::allows('accounting.post'), 403);
        ChartOfAccounts::ensure();
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'lender' => ['required', 'string', 'max:150'],
            'principal' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999'],
            'received_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'financial_account_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        if (Loan::where('idempotency_key', $d['idempotency_key'])->exists()) {
            return back()->with('success', __('Already saved.'));
        }
        FinancialAccount::where('is_active', true)->findOrFail($d['financial_account_id']);

        try {
            DB::transaction(function () use ($d, $poster, $request) {
                $l = Loan::create($d + ['created_by' => $request->user()->id]);
                $l->update(['entry_id' => $poster->loanReceived($l->load('financialAccount.account'))->id]);
                Audit::record('loan.received', $l, new: ['lender' => $l->lender, 'principal' => $l->principal]);
            });
        } catch (AccountingException $e) {
            throw ValidationException::withMessages(['received_date' => $e->getMessage()]);
        }

        return back()->with('success', __('Loan recorded.'));
    }

    public function repay(Request $request, Loan $loan, Poster $poster): RedirectResponse
    {
        abort_unless(Gate::allows('accounting.post'), 403);
        ChartOfAccounts::ensure();
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'tx_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'principal' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'], 'interest' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'financial_account_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        if (LoanTransaction::where('idempotency_key', $d['idempotency_key'])->exists()) {
            return back()->with('success', __('Already saved.'));
        }
        $principal = number_format((float) ($d['principal'] ?? 0), 2, '.', '');
        $interest = number_format((float) ($d['interest'] ?? 0), 2, '.', '');
        if (bccomp(bcadd($principal, $interest, 2), '0', 2) <= 0) {
            throw ValidationException::withMessages(['principal' => 'Enter a principal and/or interest amount.']);
        }
        if (bccomp($principal, $loan->outstanding(), 2) > 0) {
            throw ValidationException::withMessages(['principal' => 'Principal exceeds the outstanding balance ('.$loan->outstanding().').']);
        }
        FinancialAccount::where('is_active', true)->findOrFail($d['financial_account_id']);

        try {
            DB::transaction(function () use ($d, $loan, $principal, $interest, $poster, $request) {
                $t = $loan->transactions()->create(['tx_date' => $d['tx_date'], 'principal' => $principal, 'interest' => $interest, 'financial_account_id' => $d['financial_account_id'],
                    'note' => $d['note'] ?? null, 'idempotency_key' => $d['idempotency_key'], 'created_by' => $request->user()->id]);
                $t->update(['entry_id' => $poster->loanRepayment($t->load(['loan', 'financialAccount.account']))->id]);
                Audit::record('loan.repaid', $loan, new: ['principal' => $principal, 'interest' => $interest]);
            });
        } catch (AccountingException $e) {
            throw ValidationException::withMessages(['tx_date' => $e->getMessage()]);
        }

        return back()->with('success', __('Repayment recorded.'));
    }
}
