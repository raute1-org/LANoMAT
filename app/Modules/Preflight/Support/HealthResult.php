<?php

namespace App\Modules\Preflight\Support;

use App\Modules\Preflight\Enums\HealthStatus;

final class HealthResult
{
    public function __construct(
        public readonly HealthStatus $status,
        public readonly string $message = '',
    ) {}

    public static function ok(string $message = ''): self
    {
        return new self(HealthStatus::Ok, $message);
    }

    public static function warn(string $message = ''): self
    {
        return new self(HealthStatus::Warn, $message);
    }

    public static function down(string $message = ''): self
    {
        return new self(HealthStatus::Down, $message);
    }

    public static function skipped(string $message = ''): self
    {
        return new self(HealthStatus::Skipped, $message);
    }
}
