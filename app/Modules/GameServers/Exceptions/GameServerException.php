<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Exceptions;

use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Jobs\ProvisionMatchServerJob;
use App\Modules\GameServers\Listeners\ProvisionMatchServerOnReady;
use App\Modules\Infoscreen\Exceptions\InfoscreenException;
use DomainException;

/**
 * Domain errors from the GameServers module. Mirrors
 * {@see InfoscreenException}'s
 * translation-key-carrying shape so UI callers can render a German message
 * without string-matching on `getMessage()`.
 */
class GameServerException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    /**
     * Thrown by {@see ProvisionMatchServerJob}
     * when the match's tournament has no {@see Game}
     * or the game has no `pelican_egg_id` — provisioning should never have
     * been dispatched in that case (see
     * {@see ProvisionMatchServerOnReady}),
     * but this guards the job itself against being invoked directly.
     */
    public static function noPelicanEgg(): self
    {
        return new self(
            'The match\'s game has no pelican_egg_id configured.',
            'gameservers.errors.no_pelican_egg',
        );
    }

    /**
     * Not thrown: {@see PollServerStatusJob}
     * sets the {@see ServerLink}'s status to
     * `Failed` directly (no exception) once polling exhausts its retry
     * budget without the server reaching Running — that outcome is an
     * expected, non-exceptional part of the job's control flow. This factory
     * exists to carry the matching translation key so the UI/operator can
     * render a message for that `Failed` state without string-matching.
     */
    public static function provisioningExhausted(): self
    {
        return new self(
            'The game server did not become ready in time.',
            'gameservers.errors.provisioning_exhausted',
        );
    }
}
