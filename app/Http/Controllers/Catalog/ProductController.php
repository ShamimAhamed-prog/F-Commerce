<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Audit\Audit;
use App\Domain\Catalog\ProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Gate::allows('products.view'), 403);

        $q = Product::query()->with(['category:id,name', 'images' => fn ($i) => $i->where('status', 'ready')->orderBy('position')->limit(1)->with('media')])
            ->when($request->string('search')->trim()->value(), function ($q, $s) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', 'like', $like));
            })
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('category'), fn ($q, $c) => $q->where('category_id', (int) $c))
            ->orderByDesc('id');

        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = $q->paginate($perPage)->withQueryString();
        $ids = $page->pluck('id');
        $stock = DB::table('stock_balances')->whereIn('product_id', $ids)->groupBy('product_id')
            ->selectRaw('product_id, SUM(on_hand) as on_hand, SUM(reserved) as reserved')->get()->keyBy('product_id');
        $showCost = Gate::allows('products.costs.view');

        return Inertia::render('Products/Index', [
            'products' => $page->through(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'status' => $p->status, 'type' => $p->type,
                'category' => $p->category?->name,
                'selling_price' => $p->selling_price,
                'purchase_cost' => $showCost ? $p->purchase_cost : null,
                'on_hand' => (int) ($stock[$p->id]->on_hand ?? 0),
                'thumb' => $p->images->first() ? ImageUrl::thumb($p->images->first()) : null,
            ]),
            'filters' => $request->only('search', 'status', 'category'),
            'categories' => DB::table('categories')->where('tenant_id', app(\App\Domain\Tenancy\CurrentTenant::class)->id())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        abort_unless(Gate::allows('products.create'), 403);

        return Inertia::render('Products/Form', ['product' => null, 'showCost' => Gate::allows('products.costs.view')]);
    }

    public function store(ProductRequest $request, ProductService $service): RedirectResponse
    {
        $product = $service->save($request->validated(), canSetCost: Gate::allows('products.costs.view'));

        return redirect()->route('products.edit', $product)->with('success', __('Product created.'));
    }

    public function edit(Product $product): Response
    {
        abort_unless(Gate::allows('products.view'), 403);
        $product->load(['category', 'brand', 'variants']);
        $showCost = Gate::allows('products.costs.view');

        return Inertia::render('Products/Form', [
            'showCost' => $showCost,
            'canManageImages' => Gate::allows('products.images.manage'),
            'product' => [
                ...$product->only('id', 'name', 'sku', 'description', 'type', 'selling_price', 'compare_at_price', 'tax_rate',
                    'barcode', 'weight', 'dimensions', 'status', 'reorder_threshold'),
                'purchase_cost' => $showCost ? $product->purchase_cost : null,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'variants' => $product->variants->map(fn ($v) => [
                    'id' => $v->id, 'sku' => $v->sku, 'barcode' => $v->barcode, 'options' => $v->options,
                    'selling_price' => $v->selling_price, 'purchase_cost' => $showCost ? $v->purchase_cost : null,
                ]),
            ],
            'images' => app(\App\Domain\Catalog\ImageService::class)->payload($product),
        ]);
    }

    public function update(ProductRequest $request, Product $product, ProductService $service): RedirectResponse
    {
        $service->save($request->validated(), $product, canSetCost: Gate::allows('products.costs.view'));

        return back()->with('success', __('Product saved.'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        abort_unless(Gate::allows('products.delete'), 403);

        // Products with stock history are archived, never hard-deleted (ledger is immutable).
        if (DB::table('stock_movements')->where('product_id', $product->id)->exists()) {
            $product->update(['status' => 'archived']);
            Audit::record('product.archived', $product);

            return redirect()->route('products.index')->with('success', __('Product has stock history, so it was archived instead.'));
        }

        app(\App\Domain\Catalog\ImageService::class)->deleteAllFor($product);
        Audit::record('product.deleted', $product, $product->only('name', 'sku'));
        $product->delete();

        return redirect()->route('products.index')->with('success', __('Product deleted.'));
    }
}
