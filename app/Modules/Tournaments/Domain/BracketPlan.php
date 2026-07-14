<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

use InvalidArgumentException;

/**
 * Immutable wrapper around the full set of {@see BracketMatch} value objects
 * that make up a tournament bracket.
 */
final readonly class BracketPlan
{
    /**
     * @param  array<int, BracketMatch>  $matches  keyed by match id
     */
    public function __construct(
        private array $matches,
    ) {}

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
     * The last match to be played: the one without a next match, in the
     * highest-ranked bracket (Finals > Losers > Winners).
     */
    public function finalMatch(): BracketMatch
    {
        $rank = static fn (Bracket $bracket): int => match ($bracket) {
            Bracket::Winners => 0,
            Bracket::Losers => 1,
            Bracket::Finals => 2,
        };

        $candidates = array_filter(
            $this->matches,
            static fn (BracketMatch $match): bool => $match->nextMatch === null,
        );

        if ($candidates === []) {
            throw new InvalidArgumentException('Bracket plan has no match without a next match.');
        }

        usort(
            $candidates,
            static fn (BracketMatch $a, BracketMatch $b): int => $rank($b->bracket) <=> $rank($a->bracket),
        );

        return $candidates[array_key_first($candidates)];
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
