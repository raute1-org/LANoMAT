<?php

namespace App\Modules\Tournaments\Enums;

enum MatchStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Reported = 'reported';
    case Disputed = 'disputed';
    case Completed = 'completed';

    public function label(): string
    {
        return __('tournaments.match_status.'.$this->value);
    }
}
