<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use App\Modules\Lighting\Contracts\LightingClient;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the light count and channel plan from the bridge's /api/health, cached
 * briefly. When health is unreadable it falls back to 1..4 (not cached).
 */
final readonly class TopologyResolver
{
    private const CACHE_KEY = 'lighting.topology';

    public function __construct(private LightingClient $client) {}

    public function resolve(): Topology
    {
        /** @var array{count: int, channels: int|null}|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return new Topology($cached['count'], $cached['channels'], true);
        }

        $health = $this->client->health();

        if (! $health->reachable || $health->fixtureCount === null) {
            return new Topology;
        }

        Cache::put(self::CACHE_KEY, ['count' => $health->fixtureCount, 'channels' => $health->channelsPerFixture], 30);

        return new Topology($health->fixtureCount, $health->channelsPerFixture, true);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
