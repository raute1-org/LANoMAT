<?php

namespace App\Modules\Registration\Support;

use Illuminate\Support\Str;

/**
 * Human label for a technical ticket type (`standard`). Known types come from
 * lang/de/registration.php `tickets`; unknown (orga-defined) types fall back to
 * a prettified version of the raw value.
 */
class TicketLabel
{
    public static function for(string $type): string
    {
        $key = 'registration.tickets.'.$type;
        $label = trans($key);

        return $label === $key ? Str::headline($type) : $label;
    }

    /**
     * @param  array<int, string>  $types
     * @return array<string, string>
     */
    public static function map(array $types): array
    {
        $map = [];

        foreach ($types as $type) {
            $map[$type] = self::for($type);
        }

        return $map;
    }
}
