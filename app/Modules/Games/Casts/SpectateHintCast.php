<?php

namespace App\Modules\Games\Casts;

use App\Modules\Games\Domain\SpectateHint;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Typed cast for Game::$spectate_hint.
 *
 * DB jsonb `object|null` <-> `SpectateHint`. Mirrors InstallHintCast: the
 * hint is never edited as a raw string=>string map, and a missing/empty
 * value always decodes to an empty (all-null) SpectateHint rather than null,
 * so callers (e.g. the participant match page) can read it unconditionally.
 *
 * @implements CastsAttributes<SpectateHint, mixed>
 */
class SpectateHintCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): SpectateHint
    {
        if ($value === null || $value === '' || $value === []) {
            return new SpectateHint;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("SpectateHint cast expects a JSON object for [{$key}].");
        }

        if ($decoded === []) {
            return new SpectateHint;
        }

        return SpectateHint::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $hint = $value ?? new SpectateHint;

        if (! $hint instanceof SpectateHint) {
            if (! is_array($hint)) {
                throw new InvalidArgumentException("SpectateHint cast expects an array or SpectateHint for [{$key}].");
            }

            $hint = SpectateHint::fromArray($hint);
        }

        return json_encode($hint->toArray(), JSON_THROW_ON_ERROR);
    }
}
