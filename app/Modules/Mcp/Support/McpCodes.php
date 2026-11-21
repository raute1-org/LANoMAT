<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use Illuminate\Support\Str;

/**
 * Generation and hashing for the agent pairing flow. Mirrors the native voice
 * client's DeviceCodes: user-friendly alphabet without look-alikes, only
 * SHA-256 digests at rest, constant-time comparisons at the call sites.
 */
final class McpCodes
{
    /** No 0/O/1/I/L — the code is read from a terminal and typed into a browser. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const USER_CODE_LENGTH = 8;

    public const TOKEN_PREFIX = 'lmcp_';

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function deviceCode(): string
    {
        return Str::random(48);
    }

    public static function bearerToken(): string
    {
        return self::TOKEN_PREFIX.Str::random(48);
    }

    public static function userCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::USER_CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public static function format(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4, 4);
    }

    public static function normalize(string $input): ?string
    {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($input));

        if (! is_string($code) || strlen($code) !== self::USER_CODE_LENGTH) {
            return null;
        }

        return $code;
    }
}
