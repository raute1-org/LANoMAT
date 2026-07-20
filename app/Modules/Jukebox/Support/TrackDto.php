<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Support;

/**
 * A searchable track as reported by a music backend (e.g. Music Assistant).
 */
final readonly class TrackDto
{
    public function __construct(
        public string $uri,
        public string $title,
        public ?string $artist = null,
        public ?int $durationSeconds = null,
        public ?string $imageUrl = null,
    ) {}
}
