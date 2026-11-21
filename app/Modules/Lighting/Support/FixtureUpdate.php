<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use InvalidArgumentException;

/**
 * One entry of a bulk update: a fixture id with the state to apply.
 */
final readonly class FixtureUpdate
{
    public function __construct(
        public int $id,
        public LightState $state,
    ) {
        if (! Fixture::validId($id)) {
            throw new InvalidArgumentException('Invalid fixture id.');
        }
    }
}
