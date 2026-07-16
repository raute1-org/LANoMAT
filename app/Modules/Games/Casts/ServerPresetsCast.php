<?php

namespace App\Modules\Games\Casts;

use App\Modules\Games\Domain\ServerPreset;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Typed cast for Game::$server_presets.
 *
 * DB jsonb `array<array{key,name,config}>` <-> `list<ServerPreset>`. Mirrors
 * MenuCast/ServerConfigCast: presets are never edited as a raw string=>string
 * map (Filament's KeyValue field mangles types on jsonb columns — see
 * roadmap insight #9), so reads always decode into ServerPreset value
 * objects and writes always go through ServerPreset::toArray() (or a plain
 * array shaped the same way).
 *
 * @implements CastsAttributes<list<ServerPreset>, mixed>
 */
class ServerPresetsCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<ServerPreset>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("ServerPresets cast expects a JSON array for [{$key}].");
        }

        return array_values(array_map(
            static fn (mixed $preset): ServerPreset => $preset instanceof ServerPreset
                ? $preset
                : ServerPreset::fromArray($preset),
            $decoded,
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $presets = $value ?? [];

        if (! is_iterable($presets)) {
            throw new InvalidArgumentException("ServerPresets cast expects an iterable of ServerPreset for [{$key}].");
        }

        $normalized = [];

        foreach ($presets as $preset) {
            $normalized[] = $preset instanceof ServerPreset
                ? $preset->toArray()
                : ServerPreset::fromArray($preset)->toArray();
        }

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }
}
