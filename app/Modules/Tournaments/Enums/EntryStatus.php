<?php

namespace App\Modules\Tournaments\Enums;

enum EntryStatus: string
{
    case Registered = 'registered';
    case CheckedIn = 'checked_in';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return __('tournaments.entry_status.'.$this->value);
    }
}
