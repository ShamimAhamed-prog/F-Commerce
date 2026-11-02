<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\AccountingException;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\Poster;
use App\Domain\Accounting\ReportService;
use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\CodSettlement;
use App\Models\FinancialAccount;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** COD reconciliation (spec §6.6): "delivered" is never treated as "paid". */
class CodController extends Controller
{
    public function index(ReportService $reports): Response
    {
        abort_unless(Gate::allows('accounting.view'), 403);
        ChartOfAccounts::ensure();

        return Inertia::render('Accounting/Cod', [
            'receivables' => $reports->codReceivables(),
            'balance' => $reports->balanceOf(ChartOfAccounts::COD_RECEIVABLE),
            'settlements' => CodSettlement::with(['order:id,order_number', 'financialAccount:id,name'])->latest('id')->limit(30)->get()
                ->map(fn ($s) => ['id' => $s->id, 'order' => $s->order->order_number, 'date' => $s->settled_date->toDateString(), 'received' => (string) $s->received, 'fee' => (string) $s->fee, 'account' => $s->financialAccount->name, 'reference' => $s->reference]),
            'financial' => FinancialAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'today' => Ledger::today(),
            'canPost' => Gate::allows('accounting.post'),
        ]);
    }

    public function settle(Request $request, ReportService $reports, Poster $poster): RedirectResponse
    {
        abort_unless(Gate::allows('accounting.post'), 403);
        ChartOfAccounts::ensure();
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'order_id' => ['required', 'integer'],
            'settled_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'received' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'fee' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'financial_account_id' => ['required', 'integer'], 'reference' => ['nullable', 'string', 'max:100'],
        ]);
        if (CodSettlement::where('idempotency_key', $d['idempotency_key'])->exists()) {
            return back()->with('success', __('Already recorded.'));
        }
        $order = Order::findOrFail($d['order_id']);
        FinancialAccount::where('is_active', true)->findOrFail($d['financial_account_id']);
        if ($order->status !== 'delivered') {
            throw ValidationException::withMessages(['order_id' => 'Only delivered orders can be settled.']);
        }
        $fee = number_format((float) ($d['fee'] ?? 0), 2, '.', '');
        $row = collect($reports->codReceivables())->firstWhere('id', $order->id);
        if (! $row || bccomp(bcadd((string) $d['received'], $fee, 2), $row['outstanding'], 2) > 0) {
            throw ValidationException::withMessages(['received' => 'Received amount plus fee exceeds the COD still outstanding ('.($row['outstanding'] ?? '0.00').').']);
        }

        try {
            DB::transaction(function () use ($d, $fee, $order, $poster, $request) {
                $s = CodSettlement::create(['order_id' => $order->id, 'settled_date' => $d['settled_date'], 'received' => $d['received'], 'fee' => $fee,
                    'financial_account_id' => $d['financial_account_id'], 'reference' => $d['reference'] ?? null, 'idempotency_key' => $d['idempotency_key'], 'created_by' => $request->user()->id]);
                $s->load(['order', 'financialAccount.account']);
                $s->update(['entry_id' => $poster->codSettlement($s)->id]);
                Audit::record('cod.settled', $order, new: ['received' => $s->received, 'fee' => $s->fee]);
            });
        } catch (AccountingException $e) {
            throw ValidationException::withMessages(['settled_date' => $e->getMessage()]);
        }

        return back()->with('success', __('COD settlement recorded.'));
    }
}
