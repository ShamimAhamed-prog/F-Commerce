<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated image delivery. The {image} binding is tenant-scoped, so another
 * tenant's id is a 404 and storage paths are never exposed.
 */
class MediaController extends Controller
{
    public function show(ProductImage $image, string $size): Response
    {
        abort_unless(Gate::allows('products.images.view'), 403);
        abort_unless(in_array($size, ['thumb', 'card', 'detail'], true) && $image->status === 'ready', 404);

        $media = $image->media;
        $path = $size === 'detail' ? $media?->path : ($media?->derivatives[$size] ?? null);
        abort_unless($path && Storage::disk($media->disk)->exists($path), 404);

        return Storage::disk($media->disk)->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
