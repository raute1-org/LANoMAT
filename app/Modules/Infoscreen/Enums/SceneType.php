<?php

namespace App\Modules\Infoscreen\Enums;

use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Schemas\InfoscreenSceneForm;
use App\Modules\Infoscreen\Listeners\BroadcastWinnerMoment;
use App\Modules\Infoscreen\Listeners\GongOnMatchLive;

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
    case Servers = 'servers';

    /**
     * Synthetic, override-only type: a tournament's winner-moment scene is
     * built and dispatched entirely by {@see BroadcastWinnerMoment}
     * in reaction to `MatchCompleted` — it is never configured as a rotation
     * entry in Filament (excluded from {@see InfoscreenSceneForm}'s
     * `type` options) and has no `InfoscreenScene` row.
     */
    case Winner = 'winner';

    /**
     * Synthetic, override-only type: the warmup->live "gong" moment is
     * built and dispatched entirely by {@see GongOnMatchLive}
     * in reaction to `MatchWentLive` — like {@see Winner}, it is never
     * configured as a rotation entry in Filament (excluded from
     * {@see InfoscreenSceneForm}'s `type` options) and has no
     * `InfoscreenScene` row.
     */
    case Gong = 'gong';

    public function label(): string
    {
        return __('infoscreen.type.'.$this->value);
    }
}
