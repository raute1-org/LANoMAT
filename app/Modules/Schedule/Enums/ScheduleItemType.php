<?php

namespace App\Modules\Schedule\Enums;

enum ScheduleItemType: string
{
    case Custom = 'custom';
    case Tournament = 'tournament';
    case Catering = 'catering';
    case Break = 'break';

    public function label(): string
    {
        return __('schedule.type.'.$this->value);
    }
}
