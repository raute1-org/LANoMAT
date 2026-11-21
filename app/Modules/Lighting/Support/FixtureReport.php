<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use DateTimeImmutable;

final readonly class FixtureReport
{
    /**
     * @param  list<Fixture>  $fixtures
     */
    public function __construct(
        public bool $reachable,
        public array $fixtures,
        public DateTimeImmutable $checkedAt,
    ) {}

    public static function unreachable(): self
    {
        return new self(false, [], new DateTimeImmutable);
    }
}
