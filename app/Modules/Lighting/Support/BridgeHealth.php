<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

final readonly class BridgeHealth
{
    /**
     * @param  array<string, mixed>  $details
     * @param  list<int>  $startAddresses
     */
    public function __construct(
        public bool $reachable,
        public bool $healthy,
        public string $message = '',
        public array $details = [],
        public ?int $fixtureCount = null,
        public ?int $channelsPerFixture = null,
        public array $startAddresses = [],
    ) {}

    public static function unreachable(string $message = ''): self
    {
        return new self(false, false, $message);
    }
}
