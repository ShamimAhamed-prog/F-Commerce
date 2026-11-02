<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;

/** SKUs are unique per tenant across BOTH products and variants (orders match by SKU). */
class SkuRegistry
{
    public static function taken(string $sku, ?int $ignoreProductId = null, ?int $ignoreVariantId = null): bool
    {
        return Product::where('sku', $sku)->when($ignoreProductId, fn ($q) => $q->where('id', '!=', $ignoreProductId))->exists()
            || ProductVariant::where('sku', $sku)->when($ignoreVariantId, fn ($q) => $q->where('id', '!=', $ignoreVariantId))->exists();
    }
}
