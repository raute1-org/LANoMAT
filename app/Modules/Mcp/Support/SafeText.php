<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use Illuminate\Support\Str;

/** Flattens contributor-controlled text before it reaches the model. */
final class SafeText
{
    public static function line(?string $value, int $limit = 200): ?string
    {
        if ($value === null) {
            return null;
        }

        $flat = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $flat === '' ? null : Str::limit($flat, $limit, '…');
    }
}
