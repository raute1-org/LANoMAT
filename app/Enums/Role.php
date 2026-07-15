<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Orga = 'orga';
    case Helper = 'helper';
    case Participant = 'participant';

    public function label(): string
    {
        return __('roles.'.$this->value);
    }
}
