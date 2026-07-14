<?php

namespace App\Modules\Teams\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function label(): string
    {
        return __('teams.role.'.$this->value);
    }
}
