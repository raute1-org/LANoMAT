<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

use InvalidArgumentException;

/**
 * A single match within a {@see BracketPlan}. Immutable value object: every
 * mutation-like operation returns a copy, leaving the original untouched.
 */
final readonly class BracketMatch
{
    public function __construct(
        public int $id,
        public int $round,
        public Bracket $bracket,
        public int $position,
        public Slot $slot1,
        public Slot $slot2,
        public ?int $nextMatch,
        public ?int $nextSlot,
        public ?int $loserNextMatch,
        public ?int $loserNextSlot,
        public ?int $score1 = null,
        public ?int $score2 = null,
        public ?int $winnerSlot = null,
    ) {
        if ($nextSlot !== null && $nextSlot !== 1 && $nextSlot !== 2) {
            throw new InvalidArgumentException('nextSlot must be 1, 2 or null.');
        }

        if ($loserNextSlot !== null && $loserNextSlot !== 1 && $loserNextSlot !== 2) {
            throw new InvalidArgumentException('loserNextSlot must be 1, 2 or null.');
        }

        if ($winnerSlot !== null && $winnerSlot !== 1 && $winnerSlot !== 2) {
            throw new InvalidArgumentException('winnerSlot must be 1, 2 or null.');
        }
    }

    /**
     * Whether this match has a recorded result (a winner).
     */
    public function isComplete(): bool
    {
        return $this->winnerSlot !== null;
    }

    /**
     * Returns a copy with slot 1 or slot 2 replaced. Used by the progressor
     * as the propagation primitive when pushing a winner/loser into a
     * downstream match's slot.
     */
    public function withSlot(int $slotNo, Slot $slot): self
    {
        return match ($slotNo) {
            1 => new self(
                id: $this->id,
                round: $this->round,
                bracket: $this->bracket,
                position: $this->position,
                slot1: $slot,
                slot2: $this->slot2,
                nextMatch: $this->nextMatch,
                nextSlot: $this->nextSlot,
                loserNextMatch: $this->loserNextMatch,
                loserNextSlot: $this->loserNextSlot,
                score1: $this->score1,
                score2: $this->score2,
                winnerSlot: $this->winnerSlot,
            ),
            2 => new self(
                id: $this->id,
                round: $this->round,
                bracket: $this->bracket,
                position: $this->position,
                slot1: $this->slot1,
                slot2: $slot,
                nextMatch: $this->nextMatch,
                nextSlot: $this->nextSlot,
                loserNextMatch: $this->loserNextMatch,
                loserNextSlot: $this->loserNextSlot,
                score1: $this->score1,
                score2: $this->score2,
                winnerSlot: $this->winnerSlot,
            ),
            default => throw new InvalidArgumentException('slotNo must be 1 or 2.'),
        };
    }

    /**
     * Returns a copy re-pointing where this match's winner and/or loser
     * should be propagated to. Generation-time routing data only — does not
     * touch result state. Used by the generator when wiring the bracket
     * graph, and by the progressor if a bracket is rewired (e.g. grand
     * final reset).
     */
    public function withRouting(
        ?int $nextMatch = null,
        ?int $nextSlot = null,
        ?int $loserNextMatch = null,
        ?int $loserNextSlot = null,
    ): self {
        return new self(
            id: $this->id,
            round: $this->round,
            bracket: $this->bracket,
            position: $this->position,
            slot1: $this->slot1,
            slot2: $this->slot2,
            nextMatch: $nextMatch ?? $this->nextMatch,
            nextSlot: $nextSlot ?? $this->nextSlot,
            loserNextMatch: $loserNextMatch ?? $this->loserNextMatch,
            loserNextSlot: $loserNextSlot ?? $this->loserNextSlot,
            score1: $this->score1,
            score2: $this->score2,
            winnerSlot: $this->winnerSlot,
        );
    }

    /**
     * Returns a copy with the match result recorded: scores and the winning
     * slot. Routing fields (nextMatch/nextSlot/loserNext*) are generation-time
     * data and are left untouched — propagating the winner/loser into
     * downstream matches is the progressor's job via {@see withSlot()}.
     */
    public function withResult(int $score1, int $score2, int $winnerSlot): self
    {
        return new self(
            id: $this->id,
            round: $this->round,
            bracket: $this->bracket,
            position: $this->position,
            slot1: $this->slot1,
            slot2: $this->slot2,
            nextMatch: $this->nextMatch,
            nextSlot: $this->nextSlot,
            loserNextMatch: $this->loserNextMatch,
            loserNextSlot: $this->loserNextSlot,
            score1: $score1,
            score2: $score2,
            winnerSlot: $winnerSlot,
        );
    }
}
