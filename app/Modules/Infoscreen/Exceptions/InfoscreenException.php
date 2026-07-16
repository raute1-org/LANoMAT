<?php

namespace App\Modules\Infoscreen\Exceptions;

use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Infoscreen\Actions\PingOrga;
use DomainException;

class InfoscreenException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    /**
     * Thrown by {@see DrawTombola} when the
     * eligible pool (checked-in registrations of the event, not yet drawn)
     * is empty — either nobody is checked in yet, or every checked-in
     * registration has already won a prize.
     */
    public static function noEligibleEntrants(): self
    {
        return new self('There are no eligible entrants left to draw from.', 'infoscreen.errors.no_eligible_entrants');
    }

    /**
     * Thrown by {@see PingOrga} when the
     * optional "three words" exceed the word-count limit. Validated in the
     * Action itself (not only the FormRequest) per the M4 multi-entry-point
     * lesson — a future entry point (Discord slash command, Filament) must
     * not be able to persist an arbitrarily long message.
     */
    public static function tooManyWords(): self
    {
        return new self('The orga ping message may contain at most three words.', 'infoscreen.errors.orga_ping_too_many_words');
    }

    /**
     * Thrown by {@see PingOrga} when the
     * optional message exceeds the character limit.
     */
    public static function orgaPingWordsTooLong(): self
    {
        return new self('The orga ping message may be at most 40 characters long.', 'infoscreen.errors.orga_ping_words_too_long');
    }
}
