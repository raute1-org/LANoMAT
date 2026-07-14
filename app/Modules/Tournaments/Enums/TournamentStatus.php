<?php

namespace App\Modules\Tournaments\Enums;

enum TournamentStatus: string
{
    case Draft = 'draft';
    case Enrollment = 'enrollment';
    case CheckIn = 'check_in';
    case Live = 'live';
    case Finished = 'finished';

    public function label(): string
    {
        return __('tournaments.status.'.$this->value);
    }
}
