<?php

declare(strict_types=1);

namespace App\Modules\Recap\Support;

/**
 * The full post-LAN recap board for an event — activity counts, tournament
 * podiums, top gallery photos, and (eventually) the MVP. Produced by
 * {@see RecapProjection::forEvent()}.
 */
final readonly class RecapBoard
{
    /**
     * @param  list<PodiumEntry>  $podiums
     * @param  list<RecapPhoto>  $topPhotos
     * @param  ?array{name: string}  $mvp
     */
    public function __construct(
        public int $participantCount,
        public int $tournamentCount,
        public int $matchesPlayed,
        public ?int $songsPlayed,
        public array $podiums,
        public array $topPhotos,
        public ?array $mvp,
    ) {}

    /**
     * @return array{participantCount: int, tournamentCount: int, matchesPlayed: int, songsPlayed: ?int, podiums: list<array<string, mixed>>, topPhotos: list<array<string, mixed>>, mvp: ?array{name: string}}
     */
    public function toArray(): array
    {
        return [
            'participantCount' => $this->participantCount,
            'tournamentCount' => $this->tournamentCount,
            'matchesPlayed' => $this->matchesPlayed,
            'songsPlayed' => $this->songsPlayed,
            'podiums' => array_map(fn (PodiumEntry $p): array => $p->toArray(), $this->podiums),
            'topPhotos' => array_map(fn (RecapPhoto $p): array => $p->toArray(), $this->topPhotos),
            'mvp' => $this->mvp,
        ];
    }
}
