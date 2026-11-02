<?php

namespace App\Jobs;

use App\Domain\Catalog\ImageProcessor;
use App\Domain\Catalog\ImageService;
use App\Domain\Tenancy\CurrentTenant;
use App\Models\MediaFile;
use App\Models\ProductImage;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Generates derivatives for one image. Carries the tenant id and re-establishes
 * tenant context (spec §3.2 #7).
 *
 * - add: new media for a new slot
 * - replace: new media; the OLD media is deleted only after success
 * - transform: re-derives from the current detail image into the same media row
 */
class ProcessProductImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $tenantId;

    public function __construct(
        public int $imageId,
        public int $mediaId,
        public ?int $replacedMediaId,
        public ?array $transform,
        public bool $heic,
    ) {
        $this->tenantId = app(CurrentTenant::class)->id();
    }

    public function handle(ImageProcessor $processor, ImageService $service): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);

        app(CurrentTenant::class)->run($tenant, function () use ($processor, $service) {
            $image = ProductImage::with('product')->find($this->imageId);
            $media = MediaFile::find($this->mediaId);
            if (! $image || ! $media) {
                return; // deleted while queued
            }
            $disk = Storage::disk($media->disk);
            $isTransform = $this->transform !== null;
            $dir = dirname($media->original_path ?? $media->path);

            try {
                $source = $disk->get($isTransform ? $media->path : $media->original_path);
                $out = $processor->derive($media->disk, $source, $dir, $this->transform, $this->heic);
            } catch (\Throwable $e) {
                Log::warning('image processing failed', ['image_id' => $this->imageId, 'error' => class_basename($e)]);
                $this->fail409($image, $media, $service);

                return;
            }

            $oldDerived = $isTransform ? array_filter([$media->path, ...array_values($media->derivatives ?? [])]) : [];
            $originalBytes = $media->original_path ? ($disk->size($media->original_path)) : 0;

            $media->update([
                'path' => $out['detail'],
                'derivatives' => ['card' => $out['card'], 'thumb' => $out['thumb']],
                'mime' => 'image/webp',
                'width' => $out['width'],
                'height' => $out['height'],
                'size' => $originalBytes + $out['bytes'],
            ]);
            foreach ($oldDerived as $p) {
                $disk->delete($p);
            }

            if ($this->replacedMediaId) {
                $oldMedia = MediaFile::find($this->replacedMediaId);
                $image->update(['media_file_id' => $media->id, 'status' => 'ready', 'error' => null, 'version' => $image->version + 1]);
                $service->purgeMedia($oldMedia); // only now that the new file is good
            } else {
                $image->update(['status' => 'ready', 'error' => null, 'version' => $image->version + ($isTransform ? 1 : 0)]);
            }
        });
    }

    private function fail409(ProductImage $image, MediaFile $media, ImageService $service): void
    {
        if ($this->replacedMediaId) {
            // Keep the old image in place; discard the failed upload.
            $service->purgeMedia($media);
            $image->update(['error' => 'Replacement could not be processed. The previous image was kept.']);

            return;
        }
        if ($this->transform !== null) {
            $image->update(['error' => 'Edit could not be applied.']);

            return;
        }
        $image->update(['status' => 'failed', 'error' => 'The image could not be processed. Remove it and try another file.']);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessProductImage job failed', ['image_id' => $this->imageId, 'error' => class_basename($e)]);
    }
}
