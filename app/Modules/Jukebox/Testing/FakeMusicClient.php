<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Testing;

use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Contracts\PlaybackControl;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use App\Modules\Jukebox\Support\NowPlayingDto;
use App\Modules\Jukebox\Support\TrackDto;

class FakeMusicClient implements MusicClient, PlaybackControl
{
    /** @var array<int, TrackDto> */
    private array $searchResults = [];

    private ?NowPlayingDto $nowPlaying = null;

    /** @var array<int, string> */
    private array $syncedQueue = [];

    private int $skipCount = 0;

    private bool $unavailable = false;

    public bool $paused = false;

    public bool $resumed = false;

    /**
     * @param  array<int, TrackDto>  $tracks
     */
    public function willReturnSearch(array $tracks): void
    {
        $this->searchResults = $tracks;
    }

    public function willReturnNowPlaying(?NowPlayingDto $nowPlaying): void
    {
        $this->nowPlaying = $nowPlaying;
    }

    /**
     * Makes every subsequent call raise {@see MusicUnavailable}, simulating
     * Music Assistant being unreachable — for tests asserting graceful
     * degradation (sync/tick must not throw).
     */
    public function willBeUnavailable(): void
    {
        $this->unavailable = true;
    }

    public function search(string $query, int $limit = 20): array
    {
        $this->assertAvailable();

        return $this->searchResults;
    }

    public function syncQueue(array $orderedUris): void
    {
        $this->assertAvailable();
        $this->syncedQueue = $orderedUris;
    }

    public function nowPlaying(): ?NowPlayingDto
    {
        $this->assertAvailable();

        return $this->nowPlaying;
    }

    public function skip(): void
    {
        $this->assertAvailable();
        $this->skipCount++;
    }

    private function assertAvailable(): void
    {
        if ($this->unavailable) {
            throw MusicUnavailable::unreachable();
        }
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->resumed = true;
    }

    /**
     * @return array<int, string>
     */
    public function syncedQueue(): array
    {
        return $this->syncedQueue;
    }

    public function skips(): int
    {
        return $this->skipCount;
    }
}
