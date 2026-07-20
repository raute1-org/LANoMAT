<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Contracts;

use App\Modules\Jukebox\Support\NowPlayingDto;
use App\Modules\Jukebox\Support\TrackDto;

/**
 * Core verbs shared by every music backend. Player/device selection is
 * config-bound inside the implementation, never a method parameter.
 *
 * Playback/device control (pause/resume) is a separate, optional capability
 * — see {@see PlaybackControl} — implemented only by backends that support
 * it.
 */
interface MusicClient
{
    /**
     * @return array<int, TrackDto>
     */
    public function search(string $query, int $limit = 20): array;

    /**
     * Make the configured player's upcoming queue equal this ordered list of
     * track URIs. This is the single operation LANoMAT uses to reflect its
     * vote order — enqueuing and reordering are the backend's concern.
     *
     * @param  array<int, string>  $orderedUris
     */
    public function syncQueue(array $orderedUris): void;

    public function nowPlaying(): ?NowPlayingDto;

    public function skip(): void;
}
