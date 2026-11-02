<?php

namespace App\Domain\Imports;

/**
 * IMG-I2: columns that look like image fields are shown as ignored and are never
 * stored or fetched. Images are added after import, in the app.
 */
class ImageColumnClassifier
{
    private const NEEDLES = ['image', 'img', 'photo', 'picture', 'thumbnail', 'gallery', 'url'];

    public static function looksLikeImage(string $header): bool
    {
        $h = strtolower(preg_replace('/[^a-z0-9]+/i', '', $header));

        foreach (self::NEEDLES as $needle) {
            if (str_contains($h, $needle)) {
                return true;
            }
        }

        return false;
    }
}
