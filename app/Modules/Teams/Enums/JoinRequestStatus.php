<?php

namespace App\Modules\Teams\Enums;

enum JoinRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return __('teams.join_status.'.$this->value);
    }
}
