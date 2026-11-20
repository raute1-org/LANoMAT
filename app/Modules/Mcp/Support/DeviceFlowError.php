<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use RuntimeException;

/** A machine-readable device-flow error, shaped like RFC 8628. */
final class DeviceFlowError extends RuntimeException
{
    public function __construct(public readonly string $error, public readonly ?int $interval = null)
    {
        parent::__construct($error);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $payload = ['error' => $this->error];

        if ($this->interval !== null) {
            $payload['interval'] = $this->interval;
        }

        return $payload;
    }
}
