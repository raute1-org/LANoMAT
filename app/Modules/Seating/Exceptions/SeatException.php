<?php

namespace App\Modules\Seating\Exceptions;

use DomainException;

class SeatException extends DomainException
{
    public static function wrongEvent(): self
    {
        return new self('The seat does not belong to the registration event.');
    }

    public static function taken(): self
    {
        return new self('This seat is already taken.');
    }
}
