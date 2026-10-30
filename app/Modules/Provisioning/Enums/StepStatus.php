<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Applied = 'applied';
    case Verified = 'verified';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
