<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

/**
 * One line of a round-robin table. Immutable.
 */
final readonly class StandingsRow
{
    public int $scoreDifference;

    public function __construct(
        public int $entryId,
        public int $rank,
        public int $played,
        public int $wins,
        public int $losses,
        public int $pointsFor,
        public int $pointsAgainst,
    ) {
        $this->scoreDifference = $pointsFor - $pointsAgainst;
    }

    public function withRank(int $rank): self
    {
        return new self($this->entryId, $rank, $this->played, $this->wins, $this->losses, $this->pointsFor, $this->pointsAgainst);
    }
}
