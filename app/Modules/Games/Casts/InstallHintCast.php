<?php

namespace App\Modules\Games\Casts;

use App\Modules\Games\Domain\InstallHint;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Typed cast for Game::$install_hint.
 *
 * DB jsonb `object|null` <-> `InstallHint`. Mirrors ServerConfigCast: the
 * hint is never edited as a raw string=>string map, and a missing/empty
 * value always decodes to an empty (all-null) InstallHint rather than null,
 * so callers (e.g. the participant Servers page) can read it
 * unconditionally.
 *
 * @implements CastsAttributes<InstallHint, mixed>
 */
class InstallHintCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): InstallHint
    {
        if ($value === null || $value === '' || $value === []) {
            return new InstallHint;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("InstallHint cast expects a JSON object for [{$key}].");
        }

        if ($decoded === []) {
            return new InstallHint;
        }

        return InstallHint::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $hint = $value ?? new InstallHint;

        if (! $hint instanceof InstallHint) {
            if (! is_array($hint)) {
                throw new InvalidArgumentException("InstallHint cast expects an array or InstallHint for [{$key}].");
            }

            $hint = InstallHint::fromArray($hint);
        }

        return json_encode($hint->toArray(), JSON_THROW_ON_ERROR);
    }
}
