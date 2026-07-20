<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Support;

/**
 * A track in the configured player's upcoming queue, as reported by a music
 * backend.
 */
final readonly class QueueTrackDto
{
    public function __construct(
        public string $queueItemId,
        public string $uri,
        public string $title,
    ) {}
}
