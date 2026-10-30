<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Support;

use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use Illuminate\Support\Facades\Cache;

/**
 * Cheap "is the music backend reachable" read for the jukebox page: one
 * read-only {@see MusicClient::nowPlaying()} call, cached briefly so page
 * views never hammer a struggling Music Assistant. Never throws.
 */
class MusicAvailability
{
    private const CACHE_KEY = 'jukebox.music-available';

    private const TTL_SECONDS = 20;

    public function __construct(private readonly MusicClient $client) {}

    public function isAvailable(): bool
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function (): bool {
            try {
                $this->client->nowPlaying();

                return true;
            } catch (MusicUnavailable) {
                return false;
            }
        });
    }
}
