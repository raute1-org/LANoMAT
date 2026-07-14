<?php

namespace App\Modules\Registration\Exceptions;

use DomainException;

class CheckInException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function unknownToken(): self
    {
        return new self('No registration matches this QR token for this event.', 'registration.checkin.errors.unknown_token');
    }

    public static function alreadyCheckedIn(): self
    {
        return new self('This registration is already checked in.', 'registration.checkin.errors.already_checked_in');
    }

    public static function notConfirmed(): self
    {
        return new self('This registration is not active.', 'registration.checkin.errors.not_confirmed');
    }
}
