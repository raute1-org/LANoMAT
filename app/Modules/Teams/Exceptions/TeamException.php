<?php

namespace App\Modules\Teams\Exceptions;

use DomainException;

class TeamException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function alreadyMember(): self
    {
        return new self('The user is already a member of this team.', 'teams.errors.already_member');
    }

    public static function requestPending(): self
    {
        return new self('A join request is already pending.', 'teams.errors.request_pending');
    }

    public static function ownerMustTransfer(): self
    {
        return new self('The owner must transfer ownership before leaving.', 'teams.errors.owner_must_transfer');
    }

    public static function notAMember(): self
    {
        return new self('The new owner must be a team member.', 'teams.errors.not_a_member');
    }
}
