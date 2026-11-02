<?php

namespace App\Domain\Imports;

/** Maps a raw sheet row through the column mapping into clean field values. */
class ProductRowNormalizer
{
    private const NUMERIC = ['purchase_cost', 'selling_price', 'compare_at_price', 'tax_rate', 'weight', 'variant_price', 'variant_cost',
        'reorder_threshold', 'opening_stock', 'variant_stock'];

    public static function normalize(array $raw, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $header => $field) {
            // Unmapped and image-like columns are dropped here: their values are never kept.
            if (! $field || ! array_key_exists($header, $raw) || ImageColumnClassifier::looksLikeImage($header)) {
                continue;
            }
            $v = trim((string) $raw[$header]);
            if ($v === '') {
                continue;
            }
            if (in_array($field, self::NUMERIC, true)) {
                $v = self::number($v);
            }
            $out[$field] = $v;
        }

        return $out;
    }

    /** Accepts "1,500", "৳1500", Bangla digits. Returns the cleaned string (validated later). */
    public static function number(string $v): string
    {
        $v = strtr($v, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        return preg_replace('/[\s,৳]|BDT|Tk\.?/iu', '', $v);
    }
}
