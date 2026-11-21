<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

/**
 * Outcome of a bridge command. Never an exception: a bridge that is off or
 * unreachable is an ordinary result (`reachable = false`).
 */
final readonly class LightingResult
{
    private function __construct(
        public bool $ok,
        public bool $reachable,
        public string $message = '',
    ) {}

    public static function ok(): self
    {
        return new self(true, true);
    }

    public static function unreachable(string $message = ''): self
    {
        return new self(false, false, $message);
    }

    /** The bridge answered with an error. */
    public static function rejected(string $message = ''): self
    {
        return new self(false, true, $message);
    }

    /** Refused locally (safety rule), nothing was sent. */
    public static function blocked(string $message): self
    {
        return new self(false, true, $message);
    }
}
