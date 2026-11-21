<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

/**
 * What the bridge reports about its lights: how many, and how many DMX
 * channels each has. Capabilities follow from the channel count (6 channels =
 * R,G,B,CW,Strobe,Dimmer; anything else or unknown = colour only in the UI).
 */
final readonly class Topology
{
    public const FALLBACK_COUNT = 4;

    public function __construct(
        public int $count = self::FALLBACK_COUNT,
        public ?int $channelsPerFixture = null,
        public bool $known = false,
    ) {}

    /** @return list<int> */
    public function ids(): array
    {
        return $this->count >= 1 ? range(1, $this->count) : [];
    }

    public function hasFixture(int $id): bool
    {
        return $id >= 1 && $id <= $this->count;
    }

    public function hasExtendedChannels(): bool
    {
        return $this->channelsPerFixture !== null && $this->channelsPerFixture >= 6;
    }

    public function supportsBrightness(): bool
    {
        return $this->hasExtendedChannels();
    }

    public function supportsWhite(): bool
    {
        return $this->hasExtendedChannels();
    }

    public function supportsStrobe(): bool
    {
        return $this->hasExtendedChannels();
    }
}
