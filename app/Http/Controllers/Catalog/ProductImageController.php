<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\ImageConflict;
use App\Domain\Catalog\ImageLimitException;
use App\Domain\Catalog\ImageService;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** JSON API used by the ImageManager component (progress/cancel need XHR). */
class ProductImageController extends Controller
{
    public function __construct(private ImageService $images) {}

    public function index(Product $product): JsonResponse
    {
        abort_unless(Gate::allows('products.images.view'), 403);

        return response()->json($this->images->payload($product));
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $this->authorizeManage();
        $request->validate(['images' => ['required', 'array', 'min:1', 'max:20'], 'images.*' => ['file']]);

        return $this->guard(function () use ($request, $product) {
            $result = $this->images->add($product, $request->file('images'));

            return response()->json($this->images->payload($product) + ['warnings' => $result['warnings']], 201);
        });
    }

    public function replace(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->authorizeManage();
        $this->owns($product, $image);
        $request->validate(['image' => ['required', 'file']]);

        return $this->guard(function () use ($request, $product, $image) {
            $this->images->replace($image, $request->file('image'));

            return response()->json($this->images->payload($product));
        });
    }

    public function update(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->authorizeManage();
        $this->owns($product, $image);
        $data = $request->validate([
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:200'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:300'],
            'is_primary' => ['sometimes', 'boolean'],
            'etag' => ['sometimes', 'nullable', 'string'],
            'variant_ids' => ['sometimes', 'array'],
            'variant_ids.*' => ['integer'],
            'transform' => ['sometimes', 'array'],
            'transform.rotate' => ['nullable', Rule::in([90, 180, 270])],
            'transform.crop' => ['nullable', Rule::in(['square', '4:3'])],
        ]);

        return $this->guard(function () use ($product, $image, $data) {
            $this->images->update($image, $data);

            return response()->json($this->images->payload($product->refresh()));
        });
    }

    public function reorder(Request $request, Product $product): JsonResponse
    {
        $this->authorizeManage();
        $data = $request->validate(['order' => ['required', 'array', 'min:1'], 'order.*' => ['integer'], 'etag' => ['required', 'string']]);

        return $this->guard(function () use ($product, $data) {
            $this->images->reorder($product, $data['order'], $data['etag']);

            return response()->json($this->images->payload($product));
        });
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeManage();
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);

        $this->images->delete($product, $data['ids']);

        return response()->json($this->images->payload($product));
    }

    private function authorizeManage(): void
    {
        abort_unless(Gate::allows('products.images.manage'), 403);
    }

    private function owns(Product $product, ProductImage $image): void
    {
        abort_unless($image->product_id === $product->id, 404);
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (ImageConflict $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'conflict'], 409);
        } catch (ImageLimitException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['images' => [$e->getMessage()]]], 422);
        }
    }
}
