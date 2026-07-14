<?php

namespace App\Modules\Registration\Exceptions;

use DomainException;

class RegistrationException extends DomainException
{
    public static function eventNotOpen(): self
    {
        return new self('The event is not open for registration.');
    }

    public static function full(): self
    {
        return new self('The event has reached its participant limit.');
    }

    public static function invalidTicketType(string $type): self
    {
        return new self("Unknown ticket type: {$type}.");
    }

    public static function alreadyRegistered(): self
    {
        return new self('The user is already registered for this event.');
    }
}
