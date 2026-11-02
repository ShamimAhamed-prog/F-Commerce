<?php

namespace App\Domain\Sales;

/** NFR-8: normalise Bangladeshi mobiles to 01XXXXXXXXX; the original is preserved by callers. */
class PhoneNormalizer
{
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', strtr((string) $raw, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
        if (str_starts_with($d, '8801')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '1') && strlen($d) === 10) {
            $d = '0'.$d;
        }

        return preg_match('/^01[3-9]\d{8}$/', $d) ? $d : null;
    }
}
