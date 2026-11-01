<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

use RuntimeException;

/** An RFC 8628 style error of the token endpoint: rendered as `400 {"error": "<code>"}`. */
final class DeviceFlowError extends RuntimeException
{
    public function __construct(public readonly string $error, public readonly ?int $interval = null)
    {
        parent::__construct($error);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return array_filter(['error' => $this->error, 'interval' => $this->interval], fn ($v) => $v !== null);
    }
}
