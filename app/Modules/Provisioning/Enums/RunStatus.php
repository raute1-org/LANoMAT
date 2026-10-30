<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';

    public function active(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}
