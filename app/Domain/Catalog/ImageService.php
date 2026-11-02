<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\Audit;
use App\Domain\Tenancy\CurrentTenant;
use App\Jobs\ProcessProductImage;
use App\Models\MediaFile;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Manual in-app image management (spec §5.3A, IMG-1..IMG-12). */
class ImageService
{
    private const HEIC = ['image/heic', 'image/heif'];

    private const NATIVE = ['image/jpeg', 'image/png', 'image/webp'];

    private function disk(): string
    {
        return config('catalog.disk');
    }

    /** Storage path `tenants/{tenant}/products/{product}` — never guessable from client input. */
    public function dir(Product $product): string
    {
        return 'tenants/'.app(CurrentTenant::class)->id().'/products/'.$product->id;
    }

    public function maxImages(): int
    {
        return (int) (app(CurrentTenant::class)->get()?->settings['max_images_per_product'] ?? config('catalog.max_images_per_product'));
    }

    public function etag(Product $product): string
    {
        return md5($product->images()->get(['id', 'version', 'position', 'is_primary'])
            ->map(fn ($i) => "{$i->id}:{$i->version}:{$i->position}:".(int) $i->is_primary)->implode('|'));
    }

    // ---------------------------------------------------------------- add

    /** @param  UploadedFile[]  $files */
    public function add(Product $product, array $files): array
    {
        return DB::transaction(function () use ($product, $files) {
            $product = Product::lockForUpdate()->findOrFail($product->id); // serialise position/primary writes
            if ($product->images()->count() + count($files) > $this->maxImages()) {
                throw new ImageLimitException('Too many images. Maximum is '.$this->maxImages().' per product.');
            }

            $created = [];
            $warnings = [];
            $position = (int) $product->images()->max('position');
            $hasPrimary = $product->images()->where('is_primary', true)->exists();

            foreach ($files as $file) {
                [$media, $meta] = $this->storeUpload($product, $file);
                $position++;
                $image = ProductImage::create([
                    'product_id' => $product->id,
                    'media_file_id' => $media->id,
                    'position' => $position,
                    'is_primary' => ! $hasPrimary,
                    'status' => 'processing',
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]);
                $hasPrimary = true;
                if ($meta['small']) {
                    $warnings[] = $file->getClientOriginalName().' is small (under '.config('catalog.min_px_warning').' px); it may look blurry.';
                }
                Audit::record('product.image.added', $product, new: ['image_id' => $image->id]);
                ProcessProductImage::dispatch($image->id, $media->id, null, null, $meta['heic'])->afterCommit();
                $created[] = $image;
            }

            return ['images' => $created, 'warnings' => $warnings];
        });
    }

    /** @return array{0: MediaFile, 1: array{small: bool, heic: bool}} */
    private function storeUpload(Product $product, UploadedFile $file): array
    {
        $this->validateUpload($file, $mime, $w, $h);
        $this->guardQuota((int) $file->getSize());

        $ext = match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'heic' };
        $path = $this->dir($product).'/orig-'.bin2hex(random_bytes(16)).'.'.$ext;
        Storage::disk($this->disk())->put($path, file_get_contents($file->getRealPath()));

        $media = MediaFile::create([
            'disk' => $this->disk(),
            'path' => $path, // replaced by the detail derivative once processed
            'original_path' => $path,
            'mime' => $mime,
            'size' => $file->getSize(),
            'width' => $w,
            'height' => $h,
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);

        return [$media, ['small' => $w !== null && min($w, $h) < config('catalog.min_px_warning'), 'heic' => in_array($mime, self::HEIC, true)]];
    }

    /** Content-based validation: extension and client MIME are ignored. SVG/GIF are rejected. */
    private function validateUpload(UploadedFile $file, ?string &$mime, ?int &$w, ?int &$h): void
    {
        $name = $file->getClientOriginalName();
        $fail = fn (string $m) => throw ValidationException::withMessages(['images' => "{$name}: {$m}"]);

        if (! $file->isValid()) {
            $fail('upload failed.');
        }
        if ($file->getSize() > config('catalog.max_upload_kb') * 1024) {
            $fail('file is larger than '.(config('catalog.max_upload_kb') / 1024).' MB.');
        }

        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (in_array($mime, self::NATIVE, true)) {
            $info = @getimagesize($path);
            if (! $info) {
                $fail('not a valid image.');
            }
            [$w, $h] = $info;
        } elseif (in_array($mime, self::HEIC, true)) {
            if (! extension_loaded('imagick') || ! \Imagick::queryFormats('HEIC')) {
                $fail('HEIC is not supported on this server; please export the photo as JPEG.');
            }
            try {
                $im = new \Imagick;
                $im->pingImage($path);
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
            } catch (\Throwable) {
                $fail('not a valid image.');
            }
        } else {
            $fail('only JPEG, PNG and WebP images are allowed.');
        }

        $max = config('catalog.max_input_px');
        if ($w > $max || $h > $max) {
            $fail("dimensions exceed {$max}×{$max} px.");
        }
    }

    private function guardQuota(int $incoming): void
    {
        $used = (int) MediaFile::sum('size');
        if ($used + $incoming > config('catalog.storage_quota_mb') * 1024 * 1024) {
            throw new ImageLimitException('Storage quota exceeded.');
        }
    }

    // ------------------------------------------------------------ replace

    public function replace(ProductImage $image, UploadedFile $file): ProductImage
    {
        $product = $image->product;
        [$media, $meta] = $this->storeUpload($product, $file);
        $old = $image->media_file_id;

        // Slot (position, primary, alt text, variant links) is untouched. Old file is deleted
        // only after the new one has been processed successfully (job).
        $image->update(['updated_by' => auth()->id(), 'version' => $image->version + 1, 'error' => null]);
        Audit::record('product.image.replaced', $product, new: ['image_id' => $image->id]);
        ProcessProductImage::dispatch($image->id, $media->id, $old, null, $meta['heic'])->afterCommit();

        return $image;
    }

    // --------------------------------------------------------------- edit

    public function update(ProductImage $image, array $data): ProductImage
    {
        return DB::transaction(function () use ($image, $data) {
            $product = $image->product;
            $changes = array_intersect_key($data, array_flip(['alt_text', 'caption']));

            if (array_key_exists('variant_ids', $data)) {
                $ids = collect($data['variant_ids'] ?? [])->map(fn ($v) => (int) $v)->unique()->all();
                $valid = $product->variants()->whereIn('id', $ids)->pluck('id')->all();
                if (count($valid) !== count($ids)) {
                    throw ValidationException::withMessages(['variant_ids' => 'Unknown variant.']);
                }
                $image->variants()->sync($valid);
            }

            if (! empty($data['is_primary']) && ! $image->is_primary) {
                $this->assertEtag($product, $data['etag'] ?? null);
                $product->images()->where('id', '!=', $image->id)->update(['is_primary' => false]);
                $changes['is_primary'] = true;
            }

            $changes['updated_by'] = auth()->id();
            $changes['version'] = $image->version + 1;
            $image->update($changes);
            Audit::record('product.image.updated', $product, new: ['image_id' => $image->id] + array_diff_key($changes, ['updated_by' => 1]));

            if (! empty($data['transform'])) {
                $this->transform($image, $data['transform']);
            }

            return $image;
        });
    }

    public function transform(ProductImage $image, array $transform): void
    {
        $media = $image->media;
        if (! $media || $image->status !== 'ready') {
            throw ValidationException::withMessages(['transform' => 'Image is not ready.']);
        }
        ProcessProductImage::dispatch($image->id, $media->id, null, [
            'rotate' => $transform['rotate'] ?? null,
            'crop' => $transform['crop'] ?? null,
        ], false)->afterCommit();
        Audit::record('product.image.transformed', $image->product, new: ['image_id' => $image->id, 'transform' => $transform]);
    }

    // ------------------------------------------------------------ reorder

    /** @param int[] $order  complete list of this product's image ids in the new order */
    public function reorder(Product $product, array $order, ?string $etag): void
    {
        DB::transaction(function () use ($product, $order, $etag) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $this->assertEtag($product, $etag);

            $existing = $product->images()->pluck('id')->all();
            sort($existing);
            $given = array_map('intval', $order);
            $check = $given;
            sort($check);
            if ($existing !== $check) {
                throw ValidationException::withMessages(['order' => 'The order must list every image of this product exactly once.']);
            }

            foreach ($given as $i => $id) {
                ProductImage::where('id', $id)->update(['position' => $i + 1, 'version' => DB::raw('version + 1'), 'updated_by' => auth()->id()]);
            }
            Audit::record('product.image.reordered', $product, new: ['order' => $given]);
        });
    }

    private function assertEtag(Product $product, ?string $etag): void
    {
        if ($etag === null || ! hash_equals($this->etag($product), $etag)) {
            throw new ImageConflict('Images were changed by someone else. Reload and try again.');
        }
    }

    // ------------------------------------------------------------- delete

    /** @param int[] $ids */
    public function delete(Product $product, array $ids): int
    {
        return DB::transaction(function () use ($product, $ids) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $images = $product->images()->whereIn('id', $ids)->with('media')->get();

            foreach ($images as $image) {
                $this->purgeMedia($image->media);
                $image->delete(); // pivot rows cascade => variant links cleared
                Audit::record('product.image.deleted', $product, old: ['image_id' => $image->id]);
            }
            $this->normalise($product);

            return $images->count();
        });
    }

    public function deleteAllFor(Product $product): void
    {
        foreach ($product->images()->with('media')->get() as $image) {
            $this->purgeMedia($image->media);
        }
        Storage::disk($this->disk())->deleteDirectory($this->dir($product));
    }

    /** Contiguous positions + exactly one primary (promote the next if the primary was deleted). */
    public function normalise(Product $product): void
    {
        $images = $product->images()->get();
        foreach ($images as $i => $img) {
            if ($img->position !== $i + 1) {
                $img->update(['position' => $i + 1]);
            }
        }
        if ($images->isNotEmpty() && ! $images->contains('is_primary', true)) {
            $images->first()->update(['is_primary' => true, 'version' => $images->first()->version + 1]);
        }
    }

    public function purgeMedia(?MediaFile $media): void
    {
        if (! $media) {
            return;
        }
        $disk = Storage::disk($media->disk);
        foreach (array_filter([$media->original_path, $media->path, ...array_values($media->derivatives ?? [])]) as $p) {
            $disk->delete($p);
        }
        $media->delete();
    }

    // ---------------------------------------------------------- read side

    public function payload(Product $product): array
    {
        $images = $product->images()->with(['media', 'variants:id'])->get();

        return [
            'etag' => $this->etag($product),
            'max' => $this->maxImages(),
            'items' => $images->map(fn (ProductImage $i) => [
                'id' => $i->id,
                'position' => $i->position,
                'is_primary' => $i->is_primary,
                'alt_text' => $i->alt_text,
                'caption' => $i->caption,
                'status' => $i->status,
                'error' => $i->error,
                'variant_ids' => $i->variants->pluck('id'),
                'thumb' => $i->status === 'ready' ? \App\Http\Controllers\Catalog\ImageUrl::url($i, 'thumb') : null,
                'card' => $i->status === 'ready' ? \App\Http\Controllers\Catalog\ImageUrl::url($i, 'card') : null,
                'detail' => $i->status === 'ready' ? \App\Http\Controllers\Catalog\ImageUrl::url($i, 'detail') : null,
            ])->values(),
        ];
    }
}
