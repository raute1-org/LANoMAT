<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

use Illuminate\Support\Str;

/** Generation, normalization and hashing of device codes, user codes and bearer tokens. */
final class DeviceCodes
{
    /** No 0/O, 1/I/L: a code is read aloud or typed from a screen. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const USER_CODE_LENGTH = 8;

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
        return 'lvc_'.Str::random(48);
    }

    /** @return string eight characters, unformatted */
    public static function userCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::USER_CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    public static function format(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /** Uppercases and drops everything outside the alphabet; null when the length is wrong. */
    public static function normalize(string $input): ?string
    {
        $clean = preg_replace('/[^'.self::ALPHABET.']/', '', strtoupper($input)) ?? '';

        return strlen($clean) === self::USER_CODE_LENGTH ? $clean : null;
    }
}
