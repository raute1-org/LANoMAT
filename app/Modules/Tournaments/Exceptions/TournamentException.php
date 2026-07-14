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

    public static function unsupportedDoubleEliminationSize(int $count): self
    {
        return new self(
            "Double elimination is only supported for 2, 4, 6, 8 or 16 participating entries, got {$count}.",
            'tournaments.errors.unsupported_double_elimination_size',
        );
    }

    public static function matchNotReady(): self
    {
        return new self('This match is not ready to be reported.', 'tournaments.errors.match_not_ready');
    }

    public static function notAParticipant(): self
    {
        return new self('This entry is not a participant of the match.', 'tournaments.errors.not_a_participant');
    }

    public static function cannotConfirmOwnReport(): self
    {
        return new self('The reporting entry cannot confirm or dispute its own report.', 'tournaments.errors.cannot_confirm_own_report');
    }

    public static function reporterHasNoOwner(): self
    {
        return new self('Reporting entry has neither a user nor a team owner.', 'tournaments.errors.reporter_has_no_owner');
    }

    public static function tiedScore(): self
    {
        return new self('A match cannot end in a tied score.', 'tournaments.errors.tied_score');
    }

    public static function reportNotPending(): self
    {
        return new self('This report has already been resolved.', 'tournaments.errors.report_not_pending');
    }

    public static function matchNotDisputable(): self
    {
        return new self('This match can no longer be disputed.', 'tournaments.errors.match_not_disputable');
    }

    public static function entryWithdrawn(): self
    {
        return new self('This entry has been withdrawn and cannot check in.', 'tournaments.errors.entry_withdrawn');
    }
}
