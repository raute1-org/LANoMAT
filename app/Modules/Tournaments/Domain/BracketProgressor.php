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

            // Recomputed once per pass rather than per match: both are pure
            // functions of the plan, so a single O(matches) index plus one
            // fixed-point sweep replaces an O(matches) rescan per slot test.
            // Auto-advancing inside the pass only ever *fills* slots, which
            // can neither create nor remove deadness (see slotCanNeverFill()),
            // so the values stay valid for the whole pass.
            $sources = $this->slotSources($plan);
            $dead = $this->deadMatchIdSet($plan, $sources);

            foreach ($plan->matches() as $match) {
                if ($match->isDecided()) {
                    continue;
                }

                $auto = $this->autoResolvableWinner($match, $sources, $dead);

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
     * real entry and the other can never hold one — returns
     * `[entryId, winnerSlot]` for the occupant that advances. Returns null
     * if the match is not (yet) auto-resolvable (e.g. both slots still
     * pending a real report, or already playable with two real entries).
     *
     * @param  array<int, array<int, list<int>>>  $sources  see {@see slotSources()}
     * @param  array<int, true>  $dead  see {@see deadMatchIdSet()}
     * @return array{0: int, 1: int}|null
     */
    private function autoResolvableWinner(BracketMatch $match, array $sources, array $dead): ?array
    {
        $slot1Auto = $this->slotCanNeverFill($match->slot1, $sources[$match->id][1] ?? [], $dead);
        $slot2Auto = $this->slotCanNeverFill($match->slot2, $sources[$match->id][2] ?? [], $dead);

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
     * The ids of every match in `$plan` that is "dead": undecided, and with
     * neither slot able to ever hold a real entrant. Nobody can ever play
     * such a match — it is the double-bye degenerate case, e.g. a
     * losers-bracket round-1 match whose two winners-bracket feeders were
     * both byes, so neither ever routes a loser into it.
     *
     * Exposed for the persistence bridges, which give a dead match a
     * terminal status instead of leaving it looking like an upcoming match.
     *
     * @return list<int>
     */
    public function deadMatchIds(BracketPlan $plan): array
    {
        return array_keys($this->deadMatchIdSet($plan, $this->slotSources($plan)));
    }

    /**
     * Least fixed point of the mutually recursive definition
     *
     *   a match is dead      <=> it is undecided and neither slot can ever fill
     *   a slot can never fill <=> it is a bye, or it holds nothing and every
     *                             match that could deliver into it is dead
     *
     * computed iteratively rather than by recursion: start from "nothing is
     * dead" and keep sweeping while the dead set grows. Both rules are
     * monotone in the dead set, so the set only ever grows and is bounded by
     * the number of matches — the loop provably terminates after at most that
     * many sweeps, without relying on the routing graph being acyclic. A
     * cyclic (i.e. malformed) plan simply leaves its cycle out of the dead
     * set, which is the safe direction: nothing is auto-advanced.
     *
     * @param  array<int, array<int, list<int>>>  $sources  see {@see slotSources()}
     * @return array<int, true> dead match ids, as a set
     */
    private function deadMatchIdSet(BracketPlan $plan, array $sources): array
    {
        /** @var array<int, true> $dead */
        $dead = [];

        do {
            $grew = false;

            foreach ($plan->matches() as $match) {
                if (isset($dead[$match->id]) || $match->isDecided()) {
                    continue;
                }

                if (! $this->slotCanNeverFill($match->slot1, $sources[$match->id][1] ?? [], $dead)) {
                    continue;
                }

                if (! $this->slotCanNeverFill($match->slot2, $sources[$match->id][2] ?? [], $dead)) {
                    continue;
                }

                $dead[$match->id] = true;
                $grew = true;
            }
        } while ($grew);

        return $dead;
    }

    /**
     * Whether `$slot` can never hold a real entrant: it is a bye, or it holds
     * nothing (empty or still pending) and every match that could deliver an
     * entrant into it is dead. A slot with no delivering match at all is
     * vacuously unfillable — the classic dead slot, e.g. the intake slot of a
     * losers-bracket match whose winners-bracket feeder was a bye and
     * therefore records no loser route.
     *
     * A *decided* delivering match is never dead, so a slot it deliberately
     * left unfilled stays "fillable" and is not auto-resolved. That is what
     * keeps the grand-final reset intact: GF2's slots await GF1, and once GF1
     * is decided in the winners-bracket side's favour the reset is simply
     * never armed — undecided forever, but not dead.
     *
     * @param  list<int>  $sources  ids of the matches that can deliver into this slot
     * @param  array<int, true>  $dead  dead match ids known so far
     */
    private function slotCanNeverFill(Slot $slot, array $sources, array $dead): bool
    {
        if ($slot->isBye()) {
            return true;
        }

        if ($slot->isEntry()) {
            return false;
        }

        foreach ($sources as $sourceId) {
            if (! isset($dead[$sourceId])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Indexes, per match and slot number, the ids of every match that could
     * still deliver an entrant into that slot: the matches routing their
     * winner (`nextMatch`/`nextSlot`) or their loser
     * (`loserNextMatch`/`loserNextSlot`) there, plus the match a still-pending
     * slot names via {@see Slot::pendingFrom()} (a generated plan expresses
     * the dependency both ways; a plan reconstructed from persisted rows only
     * has the routing).
     *
     * @return array<int, array<int, list<int>>> match id => slot number => source match ids
     */
    private function slotSources(BracketPlan $plan): array
    {
        /** @var array<int, array<int, list<int>>> $sources */
        $sources = [];

        foreach ($plan->matches() as $match) {
            if ($match->nextMatch !== null && $match->nextSlot !== null) {
                $sources[$match->nextMatch][$match->nextSlot][] = $match->id;
            }

            if ($match->loserNextMatch !== null && $match->loserNextSlot !== null) {
                $sources[$match->loserNextMatch][$match->loserNextSlot][] = $match->id;
            }

            foreach ([1 => $match->slot1, 2 => $match->slot2] as $slotNo => $slot) {
                $pendingFrom = $slot->pendingFromMatchId();

                if ($pendingFrom !== null) {
                    $sources[$match->id][$slotNo][] = $pendingFrom;
                }
            }
        }

        return $sources;
    }
}
