<?php

namespace App\Modules\Infoscreen\Enums;

enum StatusLevel: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';

    public function label(): string
    {
        return __('infoscreen.status_level.'.$this->value);
    }
}
