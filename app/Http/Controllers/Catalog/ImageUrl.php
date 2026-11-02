<?php

namespace App\Http\Controllers\Catalog;

use App\Models\ProductImage;

/** Authenticated, tenant-scoped URLs. `v` busts caches when the image changes. */
class ImageUrl
{
    public static function url(ProductImage $image, string $size): string
    {
        return route('media.show', ['image' => $image->id, 'size' => $size, 'v' => $image->version], false);
    }

    public static function thumb(ProductImage $image): string
    {
        return self::url($image, 'thumb');
    }
}
