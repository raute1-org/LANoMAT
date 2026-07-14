<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

use InvalidArgumentException;

/**
 * Immutable wrapper around the full set of {@see BracketMatch} value objects
 * that make up a tournament bracket.
 *
 * Invariant: exactly one match in the plan has `nextMatch === null` — the
 * final match. For single elimination this is simply the last match of the
 * winners bracket. For double elimination it is the grand-final match: the
 * first grand-final match (GF1) always chains into a reset match (GF2) via
 * `nextMatch`, so GF1 is never the no-next match — GF2 is, whether or not
 * the reset is actually needed once results are known. This keeps the
 * invariant true for every plan shape the generator can produce.
 */
final readonly class BracketPlan
{
    /**
     * @param  array<int, BracketMatch>  $matches  keyed by match id
     */
    public function __construct(
        private array $matches,
    ) {
        $withoutNext = array_filter(
            $this->matches,
            static fn (BracketMatch $match): bool => $match->nextMatch === null,
        );

        if (count($withoutNext) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Bracket plan must have exactly one match without a next match, %d found.',
                    count($withoutNext),
                ),
            );
        }
    }

    /**
     * @return array<int, BracketMatch>
     */
    public function matches(): array
    {
        return $this->matches;
    }

    public function match(int $id): BracketMatch
    {
        return $this->matches[$id]
            ?? throw new InvalidArgumentException("No match with id {$id} in this bracket plan.");
    }

    /**
     * The last match to be played: the sole match without a next match. The
     * constructor guarantees exactly one such match exists.
     */
    public function finalMatch(): BracketMatch
    {
        foreach ($this->matches as $match) {
            if ($match->nextMatch === null) {
                return $match;
            }
        }

        // Unreachable: the constructor invariant guarantees exactly one match
        // without a next match.
        throw new InvalidArgumentException('Bracket plan has no match without a next match.');
    }

    /**
     * Returns a copy of the plan with the given match replaced (by id).
     */
    public function withMatch(BracketMatch $match): self
    {
        $matches = $this->matches;
        $matches[$match->id] = $match;

        return new self($matches);
    }
}
