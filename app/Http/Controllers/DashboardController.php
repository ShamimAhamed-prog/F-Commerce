<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\ReportService;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Metric definitions (spec §5.1: every metric has a documented calculation):
 *  - Gross sales  = sum of order totals for orders CREATED in the range, excluding drafts and cancelled orders.
 *  - Orders       = orders created in the range (all statuses except draft).
 *  - Net profit   = ledger revenue (net of discounts and returns) − cost of goods sold − ALL operating expenses
 *                   (advertising, courier, packaging, rent, …), for the range. It never silently excludes any of them.
 *  - Cash         = balances of cash / bank / MFS accounts in the ledger (as of today).
 *  - COD receivable = ledger balance of Courier COD receivable: shipped/delivered COD not yet remitted.
 *  - Low stock    = units whose on-hand quantity is at or below the product's reorder threshold (threshold > 0).
 *  - Missing images = non-archived products with zero images.
 */
class DashboardController extends Controller
{
    private const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90, 'mtd' => 0];

    public function __invoke(Request $request, ReportService $reports): Response
    {
        abort_unless(Gate::allows('dashboard.view'), 403);
        $tenant = app(CurrentTenant::class)->get();
        $tid = $tenant->id;
        $tz = $tenant->timezone ?? 'Asia/Dhaka';
        $range = array_key_exists($request->input('range'), self::RANGES) ? $request->input('range') : '30d';

        $todayC = Carbon::now($tz)->startOfDay();
        $fromC = $range === 'mtd' ? $todayC->copy()->startOfMonth() : $todayC->copy()->subDays(self::RANGES[$range] - 1);
        $days = $fromC->diffInDays($todayC) + 1;
        $prevFromC = $fromC->copy()->subDays($days);

        $orders = Order::where('status', '!=', 'draft')
            ->where('created_at', '>=', $prevFromC->copy()->utc())->where('created_at', '<', $todayC->copy()->addDay()->utc())
            ->get(['id', 'created_at', 'total', 'status']);

        $local = fn ($o) => $o->created_at->copy()->setTimezone($tz);
        $inRange = $orders->filter(fn ($o) => $local($o)->gte($fromC));
        $prev = $orders->filter(fn ($o) => $local($o)->lt($fromC));
        $counted = fn ($set) => $set->whereNotIn('status', ['cancelled']);

        $sum = fn ($set) => $counted($set)->reduce(fn ($a, $o) => bcadd($a, (string) $o->total, 2), '0.00');
        $byDay = $counted($inRange)->groupBy(fn ($o) => $local($o)->toDateString());
        $trend = collect(range(0, $days - 1))->map(function ($i) use ($fromC, $byDay) {
            $d = $fromC->copy()->addDays($i)->toDateString();
            $set = $byDay[$d] ?? collect();

            return ['date' => $d, 'sales' => $set->reduce(fn ($a, $o) => bcadd($a, (string) $o->total, 2), '0.00'), 'orders' => $set->count()];
        })->values();

        $gross = $sum($inRange);
        $prevGross = $sum($prev);
        $pipeline = $inRange->groupBy('status')->map->count();

        $data = [
            'range' => $range, 'from' => $fromC->toDateString(), 'to' => $todayC->toDateString(), 'days' => $days,
            'kpis' => [
                'gross_sales' => $gross, 'prev_gross_sales' => $prevGross,
                'orders' => $inRange->count(), 'prev_orders' => $prev->count(),
                'avg_order_value' => $counted($inRange)->count() ? bcdiv($gross, (string) $counted($inRange)->count(), 2) : '0.00',
            ],
            'trend' => $trend,
            'pipeline' => $pipeline,
            'attention' => $this->attention($tid, $pipeline),
            'top_sellers' => DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
                ->where('i.tenant_id', $tid)->whereNotIn('o.status', ['draft', 'cancelled'])
                ->where('o.created_at', '>=', $fromC->copy()->utc())->groupBy('i.product_id', 'i.name')
                ->orderByRaw('SUM(i.qty) DESC')->limit(5)->selectRaw('i.name, SUM(i.qty) as qty, SUM(i.line_total) as revenue')->get()
                ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => number_format((float) $r->revenue, 2, '.', '')]),
            'recent_orders' => Order::latest('id')->limit(6)->get(['id', 'order_number', 'customer_name', 'total', 'status', 'created_at']),
            'finance' => null, 'profit' => null,
        ];

        if (Gate::allows('accounting.view')) {
            ChartOfAccounts::ensure();
            $cash = FinancialAccount::with('account')->where('is_active', true)->get()
                ->reduce(fn ($a, $f) => bcadd($a, $reports->balanceOf($f->account->code), 2), '0.00');
            $data['finance'] = ['cash' => $cash, 'cod_receivable' => $reports->balanceOf(ChartOfAccounts::COD_RECEIVABLE)];
        }
        if (Gate::allows('profit.view') && Gate::allows('accounting.view')) {
            $pl = $reports->profitAndLoss($fromC->toDateString(), Ledger::today());
            $ads = collect($pl['expenses'])->firstWhere('code', ChartOfAccounts::ADVERTISING)['amount'] ?? '0.00';
            $data['profit'] = ['revenue' => $pl['total_income'], 'cogs' => $pl['total_cogs'], 'gross' => $pl['gross_profit'], 'expenses' => $pl['total_expenses'],
                'ad_spend' => $ads, 'other_opex' => bcsub($pl['total_expenses'], $ads, 2), 'net' => $pl['net_profit']];
        }

        return Inertia::render('Dashboard/Index', $data);
    }

    private function attention(int $tid, $pipeline): array
    {
        $low = DB::table('stock_balances as sb')->join('products as p', 'p.id', '=', 'sb.product_id')
            ->where('sb.tenant_id', $tid)->where('p.reorder_threshold', '>', 0)->where('p.status', '!=', 'archived')
            ->whereColumn('sb.on_hand', '<=', 'p.reorder_threshold');
        $show = Gate::allows('inventory.view');

        return [
            // Current workload, independent of the date range.
            'awaiting_confirmation' => Order::where('status', 'pending_confirmation')->count(),
            'ready_to_ship' => Order::whereIn('status', ['confirmed', 'processing', 'ready_to_ship'])->count(),
            'in_transit' => Order::where('status', 'shipped')->count(),
            'low_stock' => $show ? (int) (clone $low)->count() : null,
            'low_stock_items' => $show ? (clone $low)->orderBy('sb.on_hand')->limit(5)->get(['p.name', 'sb.on_hand', 'p.reorder_threshold'])
                ->map(fn ($r) => ['name' => $r->name, 'on_hand' => (int) $r->on_hand, 'threshold' => (int) $r->reorder_threshold]) : [],
            'missing_images' => Gate::allows('products.images.view') ? Product::where('status', '!=', 'archived')->doesntHave('images')->count() : null,
        ];
    }
}
