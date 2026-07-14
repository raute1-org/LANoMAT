<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

use InvalidArgumentException;

/**
 * Applies match results to a {@see BracketPlan} and propagates the
 * consequences: winners advance into their `nextMatch`/`nextSlot`, losers
 * drop into `loserNextMatch`/`loserNextSlot` (double elimination only), byes
 * and permanently unfillable ("dead") slots auto-resolve without a report,
 * and the grand-final reset is armed or discarded depending on which side
 * wins the first grand-final match.
 *
 * Pure domain logic: every method returns a new {@see BracketPlan}, nothing
 * is mutated in place.
 */
final class BracketProgressor
{
    /**
     * Records a played result for `$matchId` and propagates it through the
     * plan: the winner advances to its next match's slot, the loser (if
     * routed, i.e. double elimination) drops into the losers-bracket slot,
     * the grand-final reset is armed or left dead as appropriate, and any
     * now-resolvable byes/dead slots are auto-advanced recursively.
     *
     * No draws: `$score1` and `$score2` must differ.
     */
    public function apply(BracketPlan $plan, int $matchId, int $score1, int $score2): BracketPlan
    {
        if ($score1 === $score2) {
            throw new InvalidArgumentException('A match cannot end in a draw.');
        }

        $winnerSlot = $score1 > $score2 ? 1 : 2;

        return $this->applyResult($plan, $matchId, $score1, $score2, $winnerSlot);
    }

    /**
     * Records a forfeit/no-show: the forfeiting slot is scored 0 and the
     * opponent is awarded the win. Propagation is identical to {@see apply()}.
     */
    public function applyForfeit(BracketPlan $plan, int $matchId, MatchOutcome $outcome): BracketPlan
    {
        return match ($outcome) {
            MatchOutcome::ForfeitSlot1 => $this->applyResult($plan, $matchId, 0, 1, winnerSlot: 2),
            MatchOutcome::ForfeitSlot2 => $this->applyResult($plan, $matchId, 1, 0, winnerSlot: 1),
            MatchOutcome::Score => throw new InvalidArgumentException(
                'applyForfeit requires a forfeit outcome, not Score.',
            ),
        };
    }

    /**
     * Shared implementation: record the result, propagate winner/loser, and
     * recursively auto-resolve every now-resolvable bye or dead slot until
     * the plan reaches a fixed point.
     */
    private function applyResult(BracketPlan $plan, int $matchId, int $score1, int $score2, int $winnerSlot): BracketPlan
    {
        $match = $plan->match($matchId);
        $decided = $match->withResult($score1, $score2, $winnerSlot);
        $plan = $plan->withMatch($decided);

        $plan = $this->propagate($plan, $decided);

        return $this->resolveAutoAdvances($plan);
    }

    /**
     * Pushes a decided match's winner into its `nextMatch`/`nextSlot` and,
     * for double elimination, its loser into `loserNextMatch`/`loserNextSlot`.
     * Also implements the grand-final reset rule: a decided GF1 (a finals
     * match whose `nextMatch` is itself another finals match, i.e. the reset)
     * only feeds the reset match when the losers-bracket side (slot 2) won —
     * and in that case additionally drops the loser (the winners-bracket
     * side) into the reset match's other slot, since the generator cannot
     * pre-wire a loser route for a match whose loser is conditionally
     * relevant. If the winners-bracket side (slot 1) won, the reset is left
     * untouched (dead) and the tournament is decided at GF1.
     */
    private function propagate(BracketPlan $plan, BracketMatch $decided): BracketPlan
    {
        $winnerEntryId = $decided->winnerEntryId()
            ?? throw new InvalidArgumentException('Cannot propagate a match without a recorded winner.');

        if ($this->isGrandFinalWithReset($plan, $decided)) {
            if ($decided->winnerSlot === 1) {
                // Winners-bracket side won outright: the reset stays dead.
                return $plan;
            }

            // Losers-bracket side won: arm the reset with both entrants.
            // $decided->nextMatch is guaranteed non-null by isGrandFinalWithReset().
            $reset = $plan->match($decided->nextMatch ?? throw new InvalidArgumentException('Grand final with reset must have a next match.'));
            $loserEntryId = $decided->slot1->entryId()
                ?? throw new InvalidArgumentException('Grand final slot 1 must hold a real entrant once decided.');

            $reset = $reset->withSlot(1, Slot::entry($loserEntryId))
                ->withSlot(2, Slot::entry($winnerEntryId));

            return $plan->withMatch($reset);
        }

        if ($decided->nextMatch !== null && $decided->nextSlot !== null) {
            $next = $plan->match($decided->nextMatch);
            $next = $next->withSlot($decided->nextSlot, Slot::entry($winnerEntryId));
            $plan = $plan->withMatch($next);
        }

        if ($decided->loserNextMatch !== null && $decided->loserNextSlot !== null) {
            $loserEntryId = $decided->winnerSlot === 1 ? $decided->slot2->entryId() : $decided->slot1->entryId();
            $loserEntryId ??= throw new InvalidArgumentException('Losing slot must hold a real entrant once decided.');
            $loserTarget = $plan->match($decided->loserNextMatch);
            $loserTarget = $loserTarget->withSlot($decided->loserNextSlot, Slot::entry($loserEntryId));
            $plan = $plan->withMatch($loserTarget);
        }

        return $plan;
    }

    /**
     * A decided match is "GF1 with a conditional reset" when it is a finals
     * match whose `nextMatch` is itself another finals match — the only
     * shape the generator produces for a grand final that chains into a
     * reset. The plain grand-final final (a finals match with `nextMatch
     * === null`, or a reset match itself) does not match this shape.
     */
    private function isGrandFinalWithReset(BracketPlan $plan, BracketMatch $decided): bool
    {
        if ($decided->bracket !== Bracket::Finals || $decided->nextMatch === null) {
            return false;
        }

        return $plan->match($decided->nextMatch)->bracket === Bracket::Finals;
    }

    /**
     * Recursively auto-resolves every match that can advance without a
     * report: a match with a bye slot advances its real occupant, and a
     * match with a permanently unfillable ("dead") slot advances its real
     * occupant the same way. Repeats until no further match changes,
     * guaranteeing convergence (each pass either resolves at least one
     * match or leaves the plan unchanged, and the number of undecided
     * matches is finite and strictly decreases on a resolving pass).
     */
    private function resolveAutoAdvances(BracketPlan $plan): BracketPlan
    {
        do {
            $changed = false;

            foreach ($plan->matches() as $match) {
                if ($match->isDecided()) {
                    continue;
                }

                $auto = $this->autoResolvableWinner($plan, $match);

                if ($auto === null) {
                    continue;
                }

                [$winnerEntryId, $winnerSlot] = $auto;
                [$score1, $score2] = $winnerSlot === 1 ? [1, 0] : [0, 1];

                $decided = $match->withResult($score1, $score2, $winnerSlot);
                $plan = $plan->withMatch($decided);
                $plan = $this->propagate($plan, $decided);

                $changed = true;
            }
        } while ($changed);

        return $plan;
    }

    /**
     * If `$match` can be auto-advanced without a report — one slot holds a
     * real entry and the other is a bye or a permanently dead slot — returns
     * `[entryId, winnerSlot]` for the occupant that advances. Returns null
     * if the match is not (yet) auto-resolvable (e.g. both slots still
     * pending a real report, or already playable with two real entries).
     *
     * @return array{0: int, 1: int}|null
     */
    private function autoResolvableWinner(BracketPlan $plan, BracketMatch $match): ?array
    {
        $slot1Auto = $match->slot1->isBye() || $this->isDeadSlot($plan, $match->id, 1);
        $slot2Auto = $match->slot2->isBye() || $this->isDeadSlot($plan, $match->id, 2);

        if ($match->slot1->isEntry() && $slot2Auto) {
            $entryId = $match->slot1->entryId()
                ?? throw new InvalidArgumentException('Slot reported as an entry must have an entry id.');

            return [$entryId, 1];
        }

        if ($match->slot2->isEntry() && $slot1Auto) {
            $entryId = $match->slot2->entryId()
                ?? throw new InvalidArgumentException('Slot reported as an entry must have an entry id.');

            return [$entryId, 2];
        }

        return null;
    }

    /**
     * A slot is "dead" — permanently unfillable, never awaiting a real
     * report — when it is currently empty and no other match in the plan
     * routes a winner (`nextMatch`/`nextSlot`) or a loser
     * (`loserNextMatch`/`loserNextSlot`) into it. This is distinct from an
     * ordinary empty slot that some other (not yet decided) match will still
     * route into once played.
     */
    private function isDeadSlot(BracketPlan $plan, int $matchId, int $slotNo): bool
    {
        $target = $plan->match($matchId);
        $slot = $slotNo === 1 ? $target->slot1 : $target->slot2;

        if (! $slot->isEmpty()) {
            return false;
        }

        foreach ($plan->matches() as $candidate) {
            if ($candidate->nextMatch === $matchId && $candidate->nextSlot === $slotNo) {
                return false;
            }

            if ($candidate->loserNextMatch === $matchId && $candidate->loserNextSlot === $slotNo) {
                return false;
            }
        }

        return true;
    }
}
