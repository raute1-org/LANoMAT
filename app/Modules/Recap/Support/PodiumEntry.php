<?php

declare(strict_types=1);

namespace App\Modules\Recap\Support;

/**
 * One finished tournament's champion, as shown on the event recap podium.
 */
final readonly class PodiumEntry
{
    public function __construct(
        public string $tournamentName,
        public string $winnerName,
    ) {}

    /**
     * @return array{tournamentName: string, winnerName: string}
     */
    public function toArray(): array
    {
        return [
            'tournamentName' => $this->tournamentName,
            'winnerName' => $this->winnerName,
        ];
    }
}
