<?php

namespace App\Modules\Tournaments\Enums;

enum TournamentFormat: string
{
    case SingleElimination = 'single_elimination';
    case DoubleElimination = 'double_elimination';
    case RoundRobin = 'round_robin';

    public function label(): string
    {
        return __('tournaments.format.'.$this->value);
    }
}
