<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

/**
 * A tournament still open for enrollment with remaining capacity — "games
 * you can still jump into". `openSpots` is `null` when the tournament has
 * no `max_entries` cap ("offen, keine feste Grenze"); still listed, since
 * it's still joinable.
 */
final readonly class FreeSlot
{
    public function __construct(
        public int $tournamentId,
        public string $name,
        public ?string $game,
        public ?int $openSpots,
    ) {}

    /**
     * @return array{tournamentId: int, name: string, game: ?string, openSpots: ?int}
     */
    public function toArray(): array
    {
        return [
            'tournamentId' => $this->tournamentId,
            'name' => $this->name,
            'game' => $this->game,
            'openSpots' => $this->openSpots,
        ];
    }
}
