<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Exceptions;

use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\Jukebox\MusicAssistant\HttpMusicClient;
use DomainException;

/**
 * The only exception {@see HttpMusicClient} throws for transport problems —
 * a connection failure, timeout, or a failed (4xx/5xx) response from Music
 * Assistant. Callers (the queue-sync job, the participant search endpoint)
 * catch this single type to degrade gracefully instead of surfacing a raw
 * Guzzle/HTTP exception; Music Assistant being unreachable must never 500 a
 * participant request. Mirrors {@see GameServerException}'s
 * translation-key-carrying shape so UI callers can render a German message
 * without string-matching on `getMessage()`.
 */
class MusicUnavailable extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    /**
     * Thrown when the request to Music Assistant could not be completed at
     * all (connection refused, DNS failure, timeout, ...).
     */
    public static function unreachable(): self
    {
        return new self(
            'Music Assistant could not be reached.',
            'jukebox.errors.unavailable',
        );
    }

    /**
     * Thrown when Music Assistant responded, but with a failed (4xx/5xx)
     * status.
     */
    public static function requestFailed(int $status): self
    {
        return new self(
            "Music Assistant responded with an error status ({$status}).",
            'jukebox.errors.unavailable',
        );
    }
}
