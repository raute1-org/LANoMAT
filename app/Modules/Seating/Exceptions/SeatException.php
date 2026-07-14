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
}
