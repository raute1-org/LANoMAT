<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Exceptions;

use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Jobs\ProvisionMatchServerJob;
use App\Modules\GameServers\Listeners\ProvisionMatchServerOnReady;
use App\Modules\GameServers\Support\EffectiveConfig;
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

    /**
     * Thrown by {@see EffectiveConfig::resolve()} when both a preset key and
     * an uploaded config path are supplied — exactly one effective config
     * must reach the server (roadmap 6.6: "genau eine Config auf dem Server
     * ausgeführt — eine Wahrheit"), so supplying both is a caller error
     * rather than an ambiguity to silently resolve.
     */
    public static function bothPresetAndUpload(): self
    {
        return new self(
            'A server preset and an uploaded config were both supplied; exactly one is allowed.',
            'gameservers.errors.both_preset_and_upload',
        );
    }

    /**
     * Thrown by {@see EffectiveConfig::resolve()} when the given preset key
     * does not exist on the game's server_presets.
     */
    public static function presetNotFound(string $key): self
    {
        return new self(
            "No server preset with key [{$key}] exists for this game.",
            'gameservers.errors.preset_not_found',
        );
    }
}
