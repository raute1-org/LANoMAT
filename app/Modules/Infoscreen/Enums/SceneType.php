<?php

namespace App\Modules\Infoscreen\Enums;

enum SceneType: string
{
    case Bracket = 'bracket';
    case UpcomingMatches = 'upcoming_matches';
    case Schedule = 'schedule';
    case Announcement = 'announcement';
    case Seatmap = 'seatmap';
    case PaymentQr = 'payment_qr';
    case Sponsors = 'sponsors';
    case Tombola = 'tombola';
    case Status = 'status';

    public function label(): string
    {
        return __('infoscreen.type.'.$this->value);
    }
}
