<?php

namespace App\Modules\Registration\Exceptions;

use DomainException;

class RegistrationException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function eventNotOpen(): self
    {
        return new self('The event is not open for registration.', 'registration.errors.event_not_open');
    }

    public static function full(): self
    {
        return new self('The event has reached its participant limit.', 'registration.errors.full');
    }

    public static function invalidTicketType(string $type): self
    {
        return new self("Unknown ticket type: {$type}.", 'registration.errors.invalid_ticket');
    }

    public static function alreadyRegistered(): self
    {
        return new self('The user is already registered for this event.', 'registration.errors.already_registered');
    }
}
