<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\OrderService;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Gate::allows('orders.view'), 403);

        $page = Order::query()
            ->when($request->string('search')->trim()->value(), function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($w) => $w->where('order_number', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('customer_name', 'like', $like)->orWhere('external_ref', 'like', $like));
            })
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate(min(100, max(1, (int) $request->input('per_page', 25))))->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $page->through(fn (Order $o) => $o->only('id', 'order_number', 'customer_name', 'phone', 'status', 'total', 'cod_amount', 'created_at')),
            'filters' => $request->only('search', 'status'),
            'statuses' => array_unique([...array_keys(OrderService::TRANSITIONS), 'cancelled', 'returned', 'delivery_failed', 'delivered']),
        ]);
    }

    public function create(): Response
    {
        abort_unless(Gate::allows('orders.create'), 403);

        return Inertia::render('Orders/Create');
    }

    /** JSON: sellable units for the item picker (simple products and variants). */
    public function productSearch(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('orders.create'), 403);
        $q = trim((string) $request->input('q', ''));
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $tid = app(CurrentTenant::class)->id();

        $rows = DB::table('products as p')->leftJoin('product_variants as v', 'v.product_id', '=', 'p.id')
            ->leftJoin('stock_balances as sb', fn ($j) => $j->on('sb.product_id', '=', 'p.id')->whereRaw('sb.variant_id = COALESCE(v.id, 0)')->where('sb.tenant_id', $tid))
            ->where('p.tenant_id', $tid)->where('p.status', '!=', 'archived')
            ->where(fn ($w) => $w->where('p.type', 'simple')->orWhereNotNull('v.id'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like)->orWhere('v.sku', 'like', $like)))
            ->orderBy('p.name')->limit(20)
            ->get(['p.id as product_id', 'v.id as variant_id', 'p.name', 'p.sku as psku', 'v.sku as vsku', 'v.options', 'p.selling_price as pprice', 'v.selling_price as vprice',
                DB::raw('COALESCE(sb.on_hand,0) - COALESCE(sb.reserved,0) as available')]);

        return response()->json($rows->map(fn ($r) => [
            'product_id' => $r->product_id, 'variant_id' => $r->variant_id,
            'name' => $r->name.($r->options ? ' — '.implode(' / ', json_decode($r->options, true)) : ''),
            'sku' => $r->vsku ?? $r->psku, 'price' => number_format((float) ($r->vprice ?? $r->pprice), 2, '.', ''), 'available' => (int) $r->available,
        ]));
    }

    public function store(Request $request, OrderService $orders): RedirectResponse
    {
        abort_unless(Gate::allows('orders.create'), 403);
        $money = ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999'];
        $d = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:500'],
            'area' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['draft', 'pending_confirmation'])],
            'order_discount' => $money, 'shipping_charge' => $money, 'other_charges' => $money, 'tax_amount' => $money, 'advance' => $money,
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => $money,
            'items.*.discount' => $money,
        ]);

        $order = $orders->create($d, $d['idempotency_key']);

        return redirect()->route('orders.show', $order)->with('success', __('Order :n saved.', ['n' => $order->order_number]));
    }

    public function show(Order $order): Response
    {
        abort_unless(Gate::allows('orders.view'), 403);
        $order->load(['items', 'histories', 'customer:id,name,phone']);
        $next = OrderService::TRANSITIONS[$order->status] ?? [];

        return Inertia::render('Orders/Show', [
            'order' => $order->toArray(),
            'actions' => array_values(array_filter($next, fn ($s) => Gate::allows($s === 'cancelled' ? 'orders.cancel' : 'orders.update'))),
        ]);
    }

    public function transition(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $d = $request->validate(['status' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:300']]);
        abort_unless(Gate::allows($d['status'] === 'cancelled' ? 'orders.cancel' : 'orders.update'), 403);

        $orders->transition($order, $d['status'], $d['note'] ?? null);

        return back()->with('success', __('Order updated.'));
    }

    public function invoice(Order $order): Response
    {
        abort_unless(Gate::allows('orders.view'), 403);
        $t = app(CurrentTenant::class)->get();

        return Inertia::render('Orders/Invoice', [
            'order' => $order->load('items')->toArray(),
            'business' => ['name' => $t->name, 'address' => $t->address, 'phone' => $t->phone, 'details' => $t->invoice_details],
        ]);
    }
}
