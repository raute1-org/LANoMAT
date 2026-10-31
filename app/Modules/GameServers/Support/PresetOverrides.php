<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Domain\ServerPreset;
use App\Modules\GameServers\Actions\CreatePermanentServer;
use App\Modules\GameServers\Actions\StartServerFromPreset;
use App\Modules\GameServers\Exceptions\GameServerException;

/**
 * Applies the orga's per-server environment overrides to a preset's config.
 * Shared by {@see StartServerFromPreset} (event servers) and
 * {@see CreatePermanentServer}; only keys the preset lists as overridable pass.
 */
final class PresetOverrides
{
    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     *
     * @throws GameServerException when an override targets a key the preset keeps fixed
     */
    public static function apply(ServerPreset $preset, array $overrides): array
    {
        $config = $preset->config->toArray();
        $environment = is_array($config['environment'] ?? null) ? $config['environment'] : [];

        foreach ($overrides as $key => $value) {
            if (! in_array((string) $key, $preset->config->overridable, true)) {
                throw GameServerException::overrideNotAllowed((string) $key);
            }

            $environment[(string) $key] = $value;
        }

        if ($environment !== []) {
            $config['environment'] = $environment;
        }

        return $config;
    }
}
