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
    ) {
        if ($nextSlot !== null && $nextSlot !== 1 && $nextSlot !== 2) {
            throw new InvalidArgumentException('nextSlot must be 1, 2 or null.');
        }

        if ($loserNextSlot !== null && $loserNextSlot !== 1 && $loserNextSlot !== 2) {
            throw new InvalidArgumentException('loserNextSlot must be 1, 2 or null.');
        }
    }

    /**
     * Returns a copy with slot 1 or slot 2 replaced.
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
            ),
            default => throw new InvalidArgumentException('slotNo must be 1 or 2.'),
        };
    }

    /**
     * Returns a copy re-pointing where this match's winner and/or loser
     * should be propagated to. Used by the progressor when wiring or
     * rewiring the bracket graph; does not itself resolve a winner.
     */
    public function withResult(
        ?int $winnerSlot = null,
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
            nextSlot: $nextSlot ?? $winnerSlot ?? $this->nextSlot,
            loserNextMatch: $loserNextMatch ?? $this->loserNextMatch,
            loserNextSlot: $loserNextSlot ?? $this->loserNextSlot,
        );
    }
}
