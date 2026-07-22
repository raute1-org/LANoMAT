<?php

namespace App\Modules\Preflight\Enums;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Down = 'down';
    case Skipped = 'skipped';
}
