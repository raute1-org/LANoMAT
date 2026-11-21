<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Enums;

/**
 * LANoMAT events a rule can react to (existing event-bus events only).
 */
enum LightingTrigger: string
{
    case TournamentStarted = 'tournament_started';
    case MatchReady = 'match_ready';
    case MatchLive = 'match_live';
    case MatchCompleted = 'match_completed';
    case TournamentCompleted = 'tournament_completed';
    case EventLive = 'event_live';
    case EventFinished = 'event_finished';

    public function label(): string
    {
        return __('lighting.triggers.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
