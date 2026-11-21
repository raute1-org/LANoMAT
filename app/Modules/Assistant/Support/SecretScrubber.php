<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

/**
 * Removes secret values from text that is about to enter the model context
 * (server logs, console answers). Replaces the value that follows a known
 * secret key with `***`; the key itself stays so the log is still readable.
 */
final class SecretScrubber
{
    private const KEYS = 'rcon_password|sv_password|sv_setsteamaccount|server_password|password|passwd|token|api[_-]?key|secret|key';

    public static function scrub(string $text): string
    {
        // `key: "value"`, `key=value`, `key value`, `key "value"` (quoted values may contain spaces).
        $text = (string) preg_replace_callback(
            '/\b('.self::KEYS.')\b(\s*[=:]\s*|\s+)("[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s;"\',]+)/iu',
            static fn (array $m): string => $m[1].$m[2].'***',
            $text,
        );

        // `Authorization: Bearer abc`, `Bearer abc`.
        return (string) preg_replace('/\b(Bearer)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ***', $text);
    }
}
