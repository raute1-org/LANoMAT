<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Models\Game;

/**
 * Estimates the RAM (in MB) a game server will need, from the effective
 * config's slot count ({@see EffectiveConfig::resolve()}'s `max_players`)
 * — the only dimension configs vary on today. Feeds both
 * {@see GuardrailPolicy::assertWithinLimits()} (enforcement) and the
 * pre-start UI readout (`Servers/Index.vue`/`Tournaments/Show.vue`), so both
 * paths use the exact same numbers.
 *
 * The formula is a deliberately simple, conservative placeholder — a fixed
 * per-server base (the OS/runtime/game-binary footprint before any player
 * joins) plus a fixed cost per configured slot — until real per-game
 * telemetry exists to refine it (see roadmap 6.9's live-stats groundwork).
 * Never trust `$config['extra']`'s free-form keys for this: only the typed
 * `max_players` field is read.
 */
final class ResourceEstimate
{
    /**
     * Base footprint (MB) for a server with zero configured slots — process
     * overhead, OS, the game server binary itself before any player joins.
     */
    private const int BASE_MB = 256;

    /**
     * Additional RAM (MB) budgeted per configured player slot.
     */
    private const int PER_SLOT_MB = 64;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function for(Game $game, array $config): int
    {
        $slots = self::slots($config);

        return self::BASE_MB + ($slots * self::PER_SLOT_MB);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function slots(array $config): int
    {
        $maxPlayers = $config['max_players'] ?? null;

        return $maxPlayers !== null ? (int) $maxPlayers : 0;
    }
}
