<?php

namespace App\Support\Csv;

/** Neutralises cells a spreadsheet would read as a formula (CSV injection). Pure, IO-free. */
class CsvSafe
{
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(?string $value): string
    {
        $value ??= '';

        if ($value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }
}
