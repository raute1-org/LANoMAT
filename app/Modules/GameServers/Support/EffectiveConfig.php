<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\UploadServerConfig;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\ProvisionMatchServerJob;

/**
 * Resolves the *one* effective game-server config fed to
 * {@see PelicanClient::createServer()}
 * (currently wired from {@see ProvisionMatchServerJob}).
 *
 * Roadmap 6.6: "genau eine Config auf dem Server ausgeführt (eine
 * Wahrheit)" — exactly one of three sources ever wins:
 *
 * 1. A chosen preset ("form mode": `$presetKey` looks up
 *    {@see Game::findPreset()} on `games.server_presets`).
 * 2. An uploaded config file ("upload mode": `$uploadedPath` points at a
 *    JSON file on the `public` disk, stored there by
 *    {@see UploadServerConfig}, shaped like
 *    {@see ServerConfig::fromArray()} expects).
 * 3. Neither supplied: the game's `default_server_config`.
 *
 * Supplying both a preset key and an upload path is a caller error (not an
 * ambiguity to silently prioritize one over the other) and throws
 * {@see GameServerException::bothPresetAndUpload()}.
 */
final class EffectiveConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function resolve(Game $game, ?string $presetKey, ?string $uploadedPath): array
    {
        if ($presetKey !== null && $uploadedPath !== null) {
            throw GameServerException::bothPresetAndUpload();
        }

        if ($presetKey !== null) {
            $preset = $game->findPreset($presetKey);

            if ($preset === null) {
                throw GameServerException::presetNotFound($presetKey);
            }

            return $preset->config->toArray();
        }

        if ($uploadedPath !== null) {
            return ServerConfig::fromStoragePath($uploadedPath)->toArray();
        }

        return $game->default_server_config->toArray();
    }
}
