<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The cache slot of the {@see LocationSnapshot}. Requests only ever read it; the scheduled
 * `presence:refresh-locations` command (and the job a cache miss queues) is what fills it, so no
 * request waits on a voice sidecar, LiveKit or Steam.
 */
final class LocationSnapshotStore
{
    private const string KEY = 'presence.locations.snapshot';

    /** Longer than the refresh interval, so one slow or failed refresh leaves the last picture in place. */
    public const int TTL_SECONDS = 300;

    public function get(): ?LocationSnapshot
    {
        $raw = Cache::get(self::KEY);

        /** @var array<string, mixed>|null $raw */
        return is_array($raw) ? LocationSnapshot::fromArray($raw) : null;
    }

    public function put(LocationSnapshot $snapshot): void
    {
        Cache::put(self::KEY, $snapshot->toArray(), self::TTL_SECONDS);
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
