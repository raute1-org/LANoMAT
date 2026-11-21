<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

/**
 * One light as the bridge reports it.
 */
final readonly class Fixture
{
    public const MIN_ID = 1;

    /** Sanity ceiling only; the real count comes from the bridge (see Topology). */
    public const MAX_ID = 64;

    public function __construct(
        public int $id,
        public LightState $state,
    ) {}

    public static function validId(int $id): bool
    {
        return $id >= self::MIN_ID && $id <= self::MAX_ID;
    }
}
