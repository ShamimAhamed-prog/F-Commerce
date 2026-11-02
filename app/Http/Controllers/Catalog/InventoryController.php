<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Audit\Audit;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\StockService;
use App\Domain\Tenancy\CurrentTenant;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /** One row per sellable unit: simple products and variants (variable parents carry no stock). */
    private function units(int $tenantId)
    {
        $simple = DB::table('products as p')
            ->leftJoin('stock_balances as sb', fn ($j) => $j->on('sb.product_id', '=', 'p.id')->where('sb.variant_id', 0)->where('sb.tenant_id', $tenantId))
            ->where('p.tenant_id', $tenantId)->where('p.type', 'simple')->where('p.status', '!=', 'archived')
            ->selectRaw("p.id as product_id, NULL as variant_id, p.name as name, p.sku as sku, NULL as options, p.reorder_threshold as reorder_threshold,
                COALESCE(sb.on_hand,0) as on_hand, COALESCE(sb.reserved,0) as reserved, COALESCE(sb.avg_cost,0) as avg_cost");

        $variants = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('stock_balances as sb', fn ($j) => $j->on('sb.product_id', '=', 'p.id')->on('sb.variant_id', '=', 'v.id')->where('sb.tenant_id', $tenantId))
            ->where('v.tenant_id', $tenantId)->where('p.status', '!=', 'archived')
            ->selectRaw("p.id as product_id, v.id as variant_id, p.name as name, v.sku as sku, v.options as options, p.reorder_threshold as reorder_threshold,
                COALESCE(sb.on_hand,0) as on_hand, COALESCE(sb.reserved,0) as reserved, COALESCE(sb.avg_cost,0) as avg_cost");

        return DB::query()->fromSub($simple->unionAll($variants), 'u');
    }

    public function index(Request $request): Response
    {
        abort_unless(Gate::allows('inventory.view'), 403);
        $tid = app(CurrentTenant::class)->id();
        $showCost = Gate::allows('products.costs.view');

        $q = $this->units($tid)
            ->when($request->string('search')->trim()->value(), function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->when($request->boolean('low_stock'), fn ($q) => $q->where('reorder_threshold', '>', 0)->whereColumn('on_hand', '<=', 'reorder_threshold'))
            ->orderBy('name')->orderBy('sku');

        $page = $q->paginate(min(100, max(1, (int) $request->input('per_page', 25))))->withQueryString();
        $value = $showCost ? $this->units($tid)->selectRaw('COALESCE(SUM(on_hand * avg_cost),0) as v')->value('v') : null;

        return Inertia::render('Inventory/Index', [
            'units' => $page->through(fn ($u) => [
                'product_id' => $u->product_id, 'variant_id' => $u->variant_id, 'name' => $u->name, 'sku' => $u->sku,
                'options' => $u->options ? json_decode($u->options, true) : null,
                'on_hand' => (int) $u->on_hand, 'reserved' => (int) $u->reserved, 'available' => (int) $u->on_hand - (int) $u->reserved,
                'reorder_threshold' => (int) $u->reorder_threshold,
                'low' => $u->reorder_threshold > 0 && $u->on_hand <= $u->reorder_threshold,
                'avg_cost' => $showCost ? number_format((float) $u->avg_cost, 2, '.', '') : null,
            ]),
            'filters' => $request->only('search', 'low_stock'),
            'valuation' => $value === null ? null : number_format((float) $value, 2, '.', ''),
            'canAdjust' => Gate::allows('inventory.adjust'),
            'financial' => Gate::allows('accounting.post') ? \App\Models\FinancialAccount::where('is_active', true)->get(['id', 'name']) : [],
        ]);
    }

    public function adjust(Request $request, StockService $stock): RedirectResponse
    {
        abort_unless(Gate::allows('inventory.adjust'), 403);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'mode' => ['required', 'in:delta,count'],
            'quantity' => ['required', 'integer', 'min:-10000000', 'max:10000000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'paid_from' => ['nullable', 'integer'],
        ]);

        $product = Product::findOrFail($data['product_id']); // tenant-scoped
        $variantId = $data['variant_id'] ?? null;
        if ($product->isVariable()) {
            $variant = $product->variants()->where('id', $variantId)->first();
            if (! $variant) {
                throw ValidationException::withMessages(['variant_id' => 'Choose a variant.']);
            }
        } elseif ($variantId) {
            throw ValidationException::withMessages(['variant_id' => 'This product has no variants.']);
        }

        $delta = $data['mode'] === 'count'
            ? $data['quantity'] - $this->onHand($product->id, $variantId)
            : $data['quantity'];
        if ($delta === 0) {
            throw ValidationException::withMessages(['quantity' => 'This does not change the stock.']);
        }
        // A cost can only be supplied for stock coming in, and only by users who may see costs.
        $cost = $delta > 0 && Gate::allows('products.costs.view') && isset($data['unit_cost']) ? (string) $data['unit_cost'] : null;

        // Incoming stock paid from a cash/bank/MFS account is a PURCHASE (Dr Inventory / Cr that account, never COGS).
        $paidFrom = $delta > 0 && $cost !== null && ! empty($data['paid_from']) && Gate::allows('accounting.post')
            ? \App\Models\FinancialAccount::with('account')->where('is_active', true)->findOrFail($data['paid_from']) : null;

        try {
            $m = $stock->record($product->id, $variantId, $paidFrom ? 'purchase' : 'adjustment', $delta, $cost, $data['reason']);
            if ($paidFrom) {
                app(\App\Domain\Accounting\Poster::class)->stockPurchase($m, $paidFrom);
            }
        } catch (InsufficientStock|\App\Domain\Accounting\AccountingException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }
        Audit::record('inventory.adjusted', $product, new: ['variant_id' => $variantId, 'qty' => $delta, 'reason' => $data['reason'], 'movement_id' => $m->id]);

        return back()->with('success', __('Stock updated.'));
    }

    public function movements(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('inventory.view'), 403);
        $data = $request->validate(['product_id' => ['required', 'integer'], 'variant_id' => ['nullable', 'integer']]);
        $showCost = Gate::allows('products.costs.view');

        return response()->json(StockMovement::where('product_id', $data['product_id'])
            ->when($data['variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v), fn ($q) => $q->whereNull('product_variant_id'))
            ->latest('id')->limit(50)->get()
            ->map(fn ($m) => ['id' => $m->id, 'type' => $m->type, 'qty' => $m->qty, 'reason' => $m->reason, 'at' => $m->created_at,
                'unit_cost' => $showCost ? $m->unit_cost : null]));
    }

    private function onHand(int $productId, ?int $variantId): int
    {
        return (int) DB::table('stock_balances')->where('product_id', $productId)->where('variant_id', $variantId ?? 0)
            ->where('tenant_id', app(CurrentTenant::class)->id())->value('on_hand');
    }
}
