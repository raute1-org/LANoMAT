<?php

namespace App\Modules\Tournaments\Exceptions;

use DomainException;

class TournamentException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function notInEnrollment(): self
    {
        return new self('The tournament is not open for enrollment.', 'tournaments.errors.not_in_enrollment');
    }

    public static function full(): self
    {
        return new self('The tournament has reached its entry limit.', 'tournaments.errors.full');
    }

    public static function alreadyEnrolled(): self
    {
        return new self('This user or team is already enrolled.', 'tournaments.errors.already_enrolled');
    }

    public static function checkinClosed(): self
    {
        return new self('Check-in is not currently open.', 'tournaments.errors.checkin_closed');
    }

    public static function rosterSizeMismatch(): self
    {
        return new self('The team roster size does not match the required team size.', 'tournaments.errors.roster_size_mismatch');
    }

    public static function alreadyStarted(): self
    {
        return new self('The tournament has already started.', 'tournaments.errors.already_started');
    }
}
