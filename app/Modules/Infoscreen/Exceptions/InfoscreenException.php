<?php

namespace App\Modules\Infoscreen\Exceptions;

use App\Modules\Infoscreen\Actions\DrawTombola;
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
}
