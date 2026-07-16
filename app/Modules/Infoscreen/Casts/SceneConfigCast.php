<?php

namespace App\Modules\Infoscreen\Casts;

use App\Modules\Infoscreen\Domain\SceneConfig;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Typed cast for InfoscreenScene::$config.
 *
 * DB jsonb `object|null` <-> `SceneConfig`. Mirrors Catering's MenuCast: the
 * config is never edited as a raw string=>string map, and a missing/empty
 * value always decodes to an empty (all-null) SceneConfig rather than null,
 * so scene components can read it unconditionally.
 *
 * @implements CastsAttributes<SceneConfig, mixed>
 */
class SceneConfigCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): SceneConfig
    {
        if ($value === null || $value === '' || $value === []) {
            return new SceneConfig;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("SceneConfig cast expects a JSON object for [{$key}].");
        }

        if ($decoded === []) {
            return new SceneConfig;
        }

        return SceneConfig::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $config = $value ?? new SceneConfig;

        if (! $config instanceof SceneConfig) {
            if (! is_array($config)) {
                throw new InvalidArgumentException("SceneConfig cast expects an array or SceneConfig for [{$key}].");
            }

            $config = SceneConfig::fromArray($config);
        }

        return json_encode($config->toArray(), JSON_THROW_ON_ERROR);
    }
}
