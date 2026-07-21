<?php

namespace App\Modules\Voting\Exceptions;

use DomainException;

class VotingException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function notOpen(): self
    {
        return new self('This poll is not currently open for voting.', 'polls.errors.not_open');
    }

    public static function alreadyVoted(): self
    {
        return new self('This user has already voted in this poll.', 'polls.errors.already_voted');
    }

    public static function optionNotInPoll(): self
    {
        return new self('The chosen option does not belong to this poll.', 'polls.errors.option_not_in_poll');
    }

    public static function alreadyOpen(): self
    {
        return new self('This poll is already open.', 'polls.errors.already_open');
    }

    public static function notOpenYet(): self
    {
        return new self('This poll has not been opened yet.', 'polls.errors.not_open_yet');
    }

    public static function alreadyClosed(): self
    {
        return new self('This poll is already closed.', 'polls.errors.already_closed');
    }

    public static function mvpPollExists(): self
    {
        return new self('This event already has an MVP poll.', 'polls.errors.mvp_poll_exists');
    }

    public static function notClosedMvpPoll(): self
    {
        return new self('This poll is not the event\'s closed MVP poll.', 'polls.errors.not_closed_mvp_poll');
    }
}
