<?php

namespace App\Modules\Voting\Enums;

enum PollKind: string
{
    case Standard = 'standard';
    case Mvp = 'mvp';

    public function label(): string
    {
        return __('polls.kind.'.$this->value);
    }
}
