<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** IMG-7 / IMG-8: products with zero images and a "next one" shortcut (no bulk matching). */
class MissingImagesController extends Controller
{
    private function query(Request $request)
    {
        return Product::query()->doesntHave('images')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('category'), fn ($q, $c) => $q->where('category_id', (int) $c))
            ->orderBy('id');
    }

    public function index(Request $request): Response
    {
        abort_unless(Gate::allows('products.images.view'), 403);

        return Inertia::render('Products/MissingImages', [
            'products' => $this->query($request)->with('category:id,name')->paginate(min(100, (int) $request->input('per_page', 25)))
                ->withQueryString()
                ->through(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'status' => $p->status, 'category' => $p->category?->name]),
            'filters' => $request->only('status', 'category'),
            'categories' => DB::table('categories')->where('tenant_id', app(\App\Domain\Tenancy\CurrentTenant::class)->id())->orderBy('name')->get(['id', 'name']),
            'canManage' => Gate::allows('products.images.manage'),
        ]);
    }

    /** Next product (after the given id) that still has no images. */
    public function next(Request $request): RedirectResponse
    {
        abort_unless(Gate::allows('products.images.manage'), 403);
        $next = $this->query($request)->where('id', '>', (int) $request->input('after', 0))->first()
            ?? $this->query($request)->first();

        return $next
            ? redirect()->route('products.edit', $next)
            : redirect()->route('products.missing-images')->with('success', __('Every product has images.'));
    }
}
