<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Exceptions;

use DomainException;

class JukeboxException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function notCheckedIn(): self
    {
        return new self('Only checked-in participants may use the jukebox.', 'jukebox.errors.not_checked_in');
    }

    public static function alreadyQueued(): self
    {
        return new self('You already have a track queued or playing.', 'jukebox.errors.already_queued');
    }

    public static function notModerator(): self
    {
        return new self('Only orga/helper users may moderate the jukebox.', 'jukebox.errors.not_moderator');
    }

    public static function noItemPlaying(): self
    {
        return new self('There is no track currently playing.', 'jukebox.errors.no_item_playing');
    }
}
