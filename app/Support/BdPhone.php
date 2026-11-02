<?php

namespace App\Support;

/** Bangladeshi mobile numbers: 01[3-9]XXXXXXXX, also written +8801… / 8801… / with spaces or dashes. */
class BdPhone
{
    /** Canonical 01XXXXXXXXX form, or null when the value is not a valid BD mobile number. */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (str_starts_with($digits, '00880')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    /** Stored forms the same number may have (for lookups against older free-text phone columns). */
    public static function variants(string $normalized): array
    {
        $rest = substr($normalized, 1);

        return [$normalized, '+880' . $rest, '880' . $rest, '+88' . $normalized, '88' . $normalized];
    }
}
