<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Casts;

use App\Modules\GameServers\Domain\JoinInfo;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Typed cast for ServerLink::$join_info.
 *
 * DB jsonb `object|null` <-> `JoinInfo`. Mirrors Games' ServerConfigCast: the
 * join info is never edited as a raw string=>string map, and a missing/empty
 * value always decodes to an empty (all-null) JoinInfo rather than null, so
 * callers can read it unconditionally.
 *
 * @implements CastsAttributes<JoinInfo, mixed>
 */
class JoinInfoCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): JoinInfo
    {
        if ($value === null || $value === '' || $value === []) {
            return new JoinInfo;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("JoinInfo cast expects a JSON object for [{$key}].");
        }

        if ($decoded === []) {
            return new JoinInfo;
        }

        return JoinInfo::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $joinInfo = $value ?? new JoinInfo;

        if (! $joinInfo instanceof JoinInfo) {
            if (! is_array($joinInfo)) {
                throw new InvalidArgumentException("JoinInfo cast expects an array or JoinInfo for [{$key}].");
            }

            $joinInfo = JoinInfo::fromArray($joinInfo);
        }

        return json_encode($joinInfo->toArray(), JSON_THROW_ON_ERROR);
    }
}
