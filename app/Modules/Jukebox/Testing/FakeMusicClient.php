<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Testing;

use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Contracts\PlaybackControl;
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

    public function search(string $query, int $limit = 20): array
    {
        return $this->searchResults;
    }

    public function syncQueue(array $orderedUris): void
    {
        $this->syncedQueue = $orderedUris;
    }

    public function nowPlaying(): ?NowPlayingDto
    {
        return $this->nowPlaying;
    }

    public function skip(): void
    {
        $this->skipCount++;
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
