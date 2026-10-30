<?php

namespace App\Modules\Tournaments\Enums;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return __('tournaments.report_status.'.$this->value);
    }
}
