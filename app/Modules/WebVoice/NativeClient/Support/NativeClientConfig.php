<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

/** Typed access to `webvoice.native`. */
final class NativeClientConfig
{
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
}
