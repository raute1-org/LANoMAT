<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Enums;

enum VmPowerState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Paused = 'paused';
    case Unknown = 'unknown';

    public static function fromProxmox(?string $status): self
    {
        return self::tryFrom((string) $status) ?? self::Unknown;
    }
}
