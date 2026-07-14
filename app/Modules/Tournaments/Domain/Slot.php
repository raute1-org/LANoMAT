<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

use InvalidArgumentException;

/**
 * A single slot in a {@see BracketMatch}: it holds exactly one of an entry,
 * a bye, or a reference to the match it is still pending from — or none of
 * those (an unfilled slot awaiting seeding).
 */
final readonly class Slot
{
    private function __construct(
        public ?int $entryId,
        public bool $bye,
        public ?int $pendingFromMatchId,
    ) {
        $facets = ($this->entryId !== null ? 1 : 0)
            + ($this->bye ? 1 : 0)
            + ($this->pendingFromMatchId !== null ? 1 : 0);

        if ($facets > 1) {
            throw new InvalidArgumentException(
                'Slot may hold at most one of entryId, bye or pendingFromMatchId.',
            );
        }
    }

    public static function entry(int $entryId): self
    {
        return new self($entryId, false, null);
    }

    public static function bye(): self
    {
        return new self(null, true, null);
    }

    public static function pendingFrom(int $matchId): self
    {
        return new self(null, false, $matchId);
    }

    public static function empty(): self
    {
        return new self(null, false, null);
    }

    public function isEntry(): bool
    {
        return $this->entryId !== null;
    }

    public function isBye(): bool
    {
        return $this->bye;
    }

    public function isPending(): bool
    {
        return $this->pendingFromMatchId !== null;
    }

    public function isEmpty(): bool
    {
        return ! $this->isEntry() && ! $this->isBye() && ! $this->isPending();
    }

    public function entryId(): ?int
    {
        return $this->entryId;
    }

    public function pendingFromMatchId(): ?int
    {
        return $this->pendingFromMatchId;
    }
}
