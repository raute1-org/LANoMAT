<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Support;

/**
 * The configured player's currently playing track, as reported by a music
 * backend.
 */
final readonly class NowPlayingDto
{
    public function __construct(
        public string $uri,
        public string $title,
        public ?string $artist,
        public ?int $durationSeconds,
        public int $positionSeconds,
        public bool $isPlaying,
    ) {}
}
