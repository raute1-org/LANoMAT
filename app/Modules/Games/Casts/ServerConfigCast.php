<?php

namespace App\Modules\Games\Casts;

use App\Modules\Games\Domain\ServerConfig;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Typed cast for Game::$default_server_config.
 *
 * DB jsonb `object|null` <-> `ServerConfig`. Mirrors Infoscreen's
 * SceneConfigCast: the config is never edited as a raw string=>string map,
 * and a missing/empty value always decodes to an empty (all-null)
 * ServerConfig rather than null, so callers (e.g. the T9 preset UI, the
 * Pelican provisioning job) can read it unconditionally.
 *
 * @implements CastsAttributes<ServerConfig, mixed>
 */
class ServerConfigCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ServerConfig
    {
        if ($value === null || $value === '' || $value === []) {
            return new ServerConfig;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("ServerConfig cast expects a JSON object for [{$key}].");
        }

        if ($decoded === []) {
            return new ServerConfig;
        }

        return ServerConfig::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $config = $value ?? new ServerConfig;

        if (! $config instanceof ServerConfig) {
            if (! is_array($config)) {
                throw new InvalidArgumentException("ServerConfig cast expects an array or ServerConfig for [{$key}].");
            }

            $config = ServerConfig::fromArray($config);
        }

        return json_encode($config->toArray(), JSON_THROW_ON_ERROR);
    }
}
