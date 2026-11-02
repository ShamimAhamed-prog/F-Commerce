<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

/**
 * Pixel work only: decode by content, apply EXIF orientation, drop metadata
 * (re-encoding as WebP discards EXIF/GPS), produce detail/card/thumb.
 */
class ImageProcessor
{
    /**
     * @return array{detail:string,card:string,thumb:string,width:int,height:int,bytes:int}  storage paths
     */
    public function derive(string $disk, string $sourceBytes, string $dir, ?array $transform = null, bool $heic = false): array
    {
        $manager = new ImageManager($heic ? new ImagickDriver : new GdDriver);

        $img = $manager->read($sourceBytes); // throws on non-decodable content
        $img->orient();                       // EXIF orientation first, then metadata is dropped on encode

        match ($transform['rotate'] ?? null) {
            90, 180, 270 => $img->rotate(-1 * (int) $transform['rotate']),
            default => null,
        };
        match ($transform['crop'] ?? null) {
            'square' => $img->cover($s = min($img->width(), $img->height()), $s),
            '4:3' => $img->cover($img->width(), (int) round($img->width() * 3 / 4)),
            default => null,
        };

        $max = config('catalog.output_px');
        $img->scaleDown($max, $max);
        $detail = (string) $img->toWebp(config('catalog.webp_quality'));
        $width = $img->width();
        $height = $img->height();

        $token = bin2hex(random_bytes(16)); // randomized; client filename is never used
        $paths = ['detail' => "{$dir}/{$token}-detail.webp"];
        $bytes = strlen($detail);
        Storage::disk($disk)->put($paths['detail'], $detail);

        $plain = new ImageManager(new GdDriver);
        foreach (config('catalog.derivatives') as $name => $px) {
            $small = $plain->read($detail)->scaleDown($px, $px);
            $data = (string) $small->toWebp(config('catalog.webp_quality'));
            $paths[$name] = "{$dir}/{$token}-{$name}.webp";
            Storage::disk($disk)->put($paths[$name], $data);
            $bytes += strlen($data);
        }

        return $paths + ['width' => $width, 'height' => $height, 'bytes' => $bytes];
    }
}
