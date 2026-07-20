<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

/**
 * A currently-playing match (`Warmup`/`Ready` in a `Live` tournament) as
 * shown on the presence board — `players` is the union of both entries'
 * rosters by name (see {@see PresenceProjection}).
 */
final readonly class LiveMatchPresence
{
    /**
     * @param  list<string>  $players
     */
    public function __construct(
        public int $matchId,
        public ?string $game,
        public string $label,
        public array $players,
    ) {}

    /**
     * @return array{matchId: int, game: ?string, label: string, players: list<string>}
     */
    public function toArray(): array
    {
        return [
            'matchId' => $this->matchId,
            'game' => $this->game,
            'label' => $this->label,
            'players' => $this->players,
        ];
    }
}
