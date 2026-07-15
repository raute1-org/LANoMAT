<?php

namespace App\Modules\Voting\Enums;

enum PollStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return __('polls.status.'.$this->value);
    }
}
