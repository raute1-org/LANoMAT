<?php

namespace App\Modules\Seating\Exceptions;

use DomainException;

class SeatException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function wrongEvent(): self
    {
        return new self('The seat does not belong to the registration event.', 'seating.errors.wrong_event');
    }

    public static function taken(): self
    {
        return new self('This seat is already taken.', 'seating.errors.taken');
    }

    public static function registrationNotConfirmed(): self
    {
        return new self('Only a confirmed registration may choose a seat.', 'seating.errors.registration_not_confirmed');
    }

    public static function eventOver(): self
    {
        return new self('Seats cannot be changed once the event is over.', 'seating.errors.event_over');
    }
}
