<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Enums;

enum InstallStatus: string
{
    case Planned = 'planned';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
