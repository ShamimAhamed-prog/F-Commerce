<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Always freshly computed from the ledger; never served from a cache as if current (§6.5). */
class ReportController extends Controller
{
    public function show(Request $request, string $type, ReportService $r): Response|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        ChartOfAccounts::ensure();
        $profit = in_array($type, ['profit-loss', 'balance-sheet'], true);
        abort_unless(in_array($type, ['trial-balance', 'profit-loss', 'balance-sheet', 'ledger', 'cash-flow', 'expense-summary', 'owner-equity', 'tax-summary'], true), 404);
        abort_unless(Gate::allows($profit ? 'profit.view' : 'accounting.view') && Gate::allows('reports.view'), 403);

        $today = Ledger::today();
        $d = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'account' => ['nullable', 'integer']]);
        $to = $d['to'] ?? $today;
        $from = $d['from'] ?? Carbon::parse($to)->startOfMonth()->toDateString();
        abort_if($from > $to, 422, 'From must not be after To.');

        $accounts = Account::orderBy('code')->get(['id', 'code', 'name']);
        $account = $type === 'ledger' ? ($d['account'] ?? null ? Account::findOrFail($d['account']) : Account::where('code', ChartOfAccounts::CASH)->first()) : null;

        $data = match ($type) {
            'trial-balance' => $r->trialBalance($to),
            'profit-loss' => $r->profitAndLoss($from, $to),
            'balance-sheet' => $r->balanceSheet($to),
            'cash-flow' => $r->cashFlow($from, $to),
            'ledger' => $r->ledger($account, $from, $to),
            'expense-summary' => $r->expenseSummary($from, $to),
            'owner-equity' => $r->ownerEquity($from, $to),
            'tax-summary' => $r->taxSummary($from, $to),
        };

        if ($request->input('format') === 'csv') {
            return $this->csv($type, $data, $from, $to);
        }

        return Inertia::render('Reports/Show', [
            'type' => $type, 'data' => $data, 'from' => $from, 'to' => $to, 'generated_at' => now()->toIso8601String(),
            'accounts' => $type === 'ledger' ? $accounts : [], 'account' => $account?->id,
            'available' => collect([
                ['key' => 'profit-loss', 'ok' => Gate::allows('profit.view')], ['key' => 'balance-sheet', 'ok' => Gate::allows('profit.view')],
                ['key' => 'trial-balance', 'ok' => true], ['key' => 'cash-flow', 'ok' => true], ['key' => 'ledger', 'ok' => true],
                ['key' => 'expense-summary', 'ok' => true], ['key' => 'owner-equity', 'ok' => true], ['key' => 'tax-summary', 'ok' => true],
            ])->where('ok', true)->pluck('key')->values(),
        ]);
    }

    /** CSV export (online-only). Every cell is neutralised against spreadsheet formula injection (spec §12). */
    private function csv(string $type, array $d, string $from, string $to): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = match ($type) {
            'trial-balance' => [['Code', 'Account', 'Type', 'Debit', 'Credit'], ...array_map(fn ($r) => [$r['code'], $r['name'], $r['type'], $r['debit'], $r['credit']], $d['rows']), ['', 'Total', '', $d['total_debit'], $d['total_credit']]],
            'profit-loss' => [['Section', 'Code', 'Account', 'Amount'],
                ...array_map(fn ($r) => ['Revenue', $r['code'], $r['name'], $r['amount']], $d['income']), ['Revenue', '', 'Total revenue', $d['total_income']],
                ...array_map(fn ($r) => ['Cost of goods', $r['code'], $r['name'], $r['amount']], $d['cogs']), ['Cost of goods', '', 'Total cost of goods', $d['total_cogs']], ['', '', 'Gross profit', $d['gross_profit']],
                ...array_map(fn ($r) => ['Expenses', $r['code'], $r['name'], $r['amount']], $d['expenses']), ['Expenses', '', 'Total expenses', $d['total_expenses']], ['', '', 'Net profit', $d['net_profit']]],
            'balance-sheet' => [['Section', 'Code', 'Account', 'Amount'],
                ...array_map(fn ($r) => ['Assets', $r['code'], $r['name'], $r['amount']], $d['assets']), ['Assets', '', 'Total assets', $d['total_assets']],
                ...array_map(fn ($r) => ['Liabilities', $r['code'], $r['name'], $r['amount']], $d['liabilities']), ['Liabilities', '', 'Total liabilities', $d['total_liabilities']],
                ...array_map(fn ($r) => ['Equity', $r['code'], $r['name'], $r['amount']], $d['equity']), ['Equity', '', 'Total equity', $d['total_equity']]],
            'cash-flow' => [['Section', 'Item', 'Amount'], ['Opening', 'Opening cash', $d['opening']],
                ...array_map(fn ($r) => ['Cash in', $r['label'], $r['amount']], $d['inflows']), ...array_map(fn ($r) => ['Cash out', $r['label'], $r['amount']], $d['outflows']), ['Closing', 'Closing cash', $d['closing']]],
            'ledger' => [['Date', 'Entry', 'Memo', 'Debit', 'Credit', 'Balance'], ['', '', 'Opening balance', '', '', $d['opening']],
                ...array_map(fn ($l) => [$l['date'], $l['entry_number'], $l['memo'], $l['debit'], $l['credit'], $l['balance']], $d['lines']), ['', '', 'Closing balance', '', '', $d['closing']]],
            'expense-summary' => [['Group', 'Item', 'Amount'], ...array_map(fn ($r) => ['Category', $r['label'], $r['amount']], $d['by_category']), ...array_map(fn ($r) => ['Month', $r['label'], $r['amount']], $d['by_month']),
                ...array_map(fn ($r) => ['Vendor', $r['label'], $r['amount']], $d['top_vendors']), ['Total', 'All expenses', $d['total']], ['Advertising', 'Advertising spend', $d['advertising']]],
            'owner-equity' => [['Item', 'Amount'], ['Capital contributed', $d['capital']], ['Drawings', $d['drawings']], ['Net owner funding', $d['net_owner_funding']], ['Loans received (liability)', $d['loans_received']], ['Loans payable balance', $d['loans_payable']]],
            'tax-summary' => [['Item', 'Amount'], ['Opening payable', $d['opening']], ['Collected', $d['collected']], ['Adjusted / paid', $d['adjusted']], ['Closing payable', $d['closing']]],
        };
        $safe = fn ($v) => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) && ! preg_match('/^-?\d+(\.\d+)?$/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($rows, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel shows Bangla text correctly
            foreach ($rows as $r) {
                fputcsv($out, array_map($safe, $r));
            }
            fclose($out);
        }, "{$type}_{$from}_{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
