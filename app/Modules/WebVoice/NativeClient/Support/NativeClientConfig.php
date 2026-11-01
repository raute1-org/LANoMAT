<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

/** Typed access to `webvoice.native`. */
final class NativeClientConfig
{
    /**
     * Version of the bearer API (`/api/voice-client/v1`). scripts/ops/build-client.sh reads this
     * line to stamp the client manifest, so keep it a plain integer literal.
     */
    public const API_VERSION = 1;

    public static function codeTtlSeconds(): int
    {
        return (int) config('webvoice.native.code_ttl');
    }

    public static function pollInterval(): int
    {
        return (int) config('webvoice.native.poll_interval');
    }

    public static function tokenTtlDays(): int
    {
        return (int) config('webvoice.native.token_ttl_days');
    }

    /** Oldest client version the server still accepts, `null` for "no floor". */
    public static function minClientVersion(): ?string
    {
        $value = config('webvoice.native.min_client_version');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Directory holding the published client builds and their manifest, or `null` when unset. */
    public static function distPath(): ?string
    {
        $value = config('webvoice.native.dist_path');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
