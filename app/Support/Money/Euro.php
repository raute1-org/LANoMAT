<?php

namespace App\Support\Money;

/** Euro amounts as integer cents: parse human input, format for display. Pure, IO-free. */
class Euro
{
    /** "35", "35,00", "35.5", "1.234,50" → cents; null/blank/invalid/negative → null. */
    public static function toCents(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace(['€', ' '], '', $value));

        if ($value === '') {
            return null;
        }

        // German thousands separator ("1.234,50"): a dot followed by exactly 3 digits and a later comma.
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** 3500 → "35,00 €". */
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }
}
