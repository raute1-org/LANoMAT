<?php

namespace App\Modules\Tournaments\Enums;

enum TournamentStatus: string
{
    case Draft = 'draft';
    case Announced = 'announced';
    case Enrollment = 'enrollment';
    case CheckIn = 'check_in';
    case Live = 'live';
    case Finished = 'finished';

    public function label(): string
    {
        return __('tournaments.status.'.$this->value);
    }

    /** Filament badge color name for the admin status badge. */
    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Finished => 'gray',
            self::Announced => 'primary',
            self::Enrollment => 'info',
            self::CheckIn => 'warning',
            self::Live => 'success',
        };
    }

    /**
     * Draft tournaments are orga-only; every later status is visible to any
     * logged-in participant (announced ones merely refuse sign-up).
     */
    public function isParticipantVisible(): bool
    {
        return $this !== self::Draft;
    }
}
