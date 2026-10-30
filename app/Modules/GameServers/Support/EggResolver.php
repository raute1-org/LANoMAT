<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;

/**
 * Turns a preset's egg reference into this panel's numeric egg id. Numeric ids
 * differ per panel, so a preset names its egg by uuid (and a fallback name) and
 * the id is resolved here against `listEggs()`. A preset without an egg
 * reference uses the game's own `pelican_egg_id`.
 */
final class EggResolver
{
    public function __construct(private readonly PelicanClient $client) {}

    /**
     * @throws GameServerException when the egg is not on the panel or no egg is configured at all
     */
    public function resolve(Game $game, ?ServerPreset $preset): string
    {
        if ($preset !== null && ($preset->eggUuid !== null || $preset->eggName !== null)) {
            $eggs = $this->client->listEggs();

            foreach ($eggs as $egg) {
                if ($preset->eggUuid !== null && $egg->uuid === $preset->eggUuid) {
                    return $egg->id;
                }
            }

            foreach ($eggs as $egg) {
                if ($preset->eggName !== null && mb_strtolower($egg->name) === mb_strtolower($preset->eggName)) {
                    return $egg->id;
                }
            }

            throw GameServerException::eggNotImported($preset->eggName ?? (string) $preset->eggUuid);
        }

        if ($game->pelican_egg_id === null || $game->pelican_egg_id === '') {
            throw GameServerException::noPelicanEgg();
        }

        return $game->pelican_egg_id;
    }
}
