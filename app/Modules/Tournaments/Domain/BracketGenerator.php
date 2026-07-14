<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

use InvalidArgumentException;

/**
 * Builds {@see BracketPlan} instances from a seeded list of entries. Pure
 * domain computation: no IO, no persistence, no framework dependencies.
 */
final class BracketGenerator
{
    /**
     * Generates a standard single-elimination bracket.
     *
     * @param  array<int, int>  $entryIds  already seeded, in order (index 0 = seed 1, index 1 = seed 2, ...)
     */
    public function singleElimination(array $entryIds): BracketPlan
    {
        $n = count($entryIds);

        if ($n < 2) {
            throw new InvalidArgumentException('Single elimination requires at least 2 entries.');
        }

        $size = $this->nextPowerOfTwo($n);
        $order = $this->seedOrder($size);

        // seed 1..n -> entryIds[seed-1]; seed n+1..size -> bye.
        $slotForSeed = function (int $seed) use ($entryIds, $n): Slot {
            return $seed <= $n ? Slot::entry($entryIds[$seed - 1]) : Slot::bye();
        };

        $matches = [];
        $id = 1;

        // Round 1: pair up consecutive positions in the seed order.
        $roundMatchIds = [];
        $matchesInRound = intdiv($size, 2);

        for ($i = 0; $i < $matchesInRound; $i++) {
            $seedA = $order[2 * $i];
            $seedB = $order[2 * $i + 1];

            $matches[$id] = new BracketMatch(
                id: $id,
                round: 1,
                bracket: Bracket::Winners,
                position: $i + 1,
                slot1: $slotForSeed($seedA),
                slot2: $slotForSeed($seedB),
                nextMatch: null,
                nextSlot: null,
                loserNextMatch: null,
                loserNextSlot: null,
            );

            $roundMatchIds[] = $id;
            $id++;
        }

        // Subsequent rounds: halve the match count each time, chaining the
        // previous round's winners into this round's slots.
        $round = 2;
        $previousRoundIds = $roundMatchIds;

        while (count($previousRoundIds) > 1) {
            $matchesInRound = intdiv(count($previousRoundIds), 2);
            $currentRoundIds = [];

            for ($i = 0; $i < $matchesInRound; $i++) {
                $feederA = $previousRoundIds[2 * $i];
                $feederB = $previousRoundIds[2 * $i + 1];

                $matches[$id] = new BracketMatch(
                    id: $id,
                    round: $round,
                    bracket: Bracket::Winners,
                    position: $i + 1,
                    slot1: Slot::pendingFrom($feederA),
                    slot2: Slot::pendingFrom($feederB),
                    nextMatch: null,
                    nextSlot: null,
                    loserNextMatch: null,
                    loserNextSlot: null,
                );

                // Wire the two feeder matches of the previous round forward.
                $matches[$feederA] = $matches[$feederA]->withRouting(nextMatch: $id, nextSlot: 1);
                $matches[$feederB] = $matches[$feederB]->withRouting(nextMatch: $id, nextSlot: 2);

                $currentRoundIds[] = $id;
                $id++;
            }

            $previousRoundIds = $currentRoundIds;
            $round++;
        }

        return new BracketPlan($matches);
    }

    /**
     * Generates a standard double-elimination bracket: a winners bracket
     * (WB) built exactly like {@see singleElimination()}, a losers bracket
     * (LB) that receives WB losers in the standard interleaved order, and a
     * grand final (GF1) that chains into a conditional reset match (GF2).
     *
     * Losers-bracket construction follows the canonical algorithm (as
     * documented for the "Superior Double Elimination Losers Bracket
     * Seeding" scheme and implemented by mainstream bracket generators):
     * alternating LB rounds either take in a fresh batch of WB losers
     * ("major" rounds, in this codebase's terminology — the brief's usage)
     * or resolve purely LB-internal ("minor" rounds). Intake rounds reorder
     * the incoming WB losers with a fixed per-size ordering (natural,
     * reverse, or reverse-half-shift) before zipping them against the
     * current LB survivors, so that a dropped WB loser does not immediately
     * re-meet an opponent from the same recent bracket lineage. This
     * ordering table is verified (by construction and by exhaustive
     * simulation during development) for size 4, 8 and 16 — the sizes this
     * engine is exhaustively tested against.
     *
     * @param  array<int, int>  $entryIds  already seeded, in order (index 0 = seed 1, index 1 = seed 2, ...)
     */
    public function doubleElimination(array $entryIds): BracketPlan
    {
        $n = count($entryIds);

        if ($n < 2) {
            throw new InvalidArgumentException('Double elimination requires at least 2 entries.');
        }

        $size = $this->nextPowerOfTwo($n);
        $order = $this->seedOrder($size);
        $wbRoundCount = (int) log($size, 2);

        $slotForSeed = function (int $seed) use ($entryIds, $n): Slot {
            return $seed <= $n ? Slot::entry($entryIds[$seed - 1]) : Slot::bye();
        };

        $matches = [];
        $id = 1;

        // --- Winners bracket (identical shape to singleElimination) ---
        // We additionally track, per WB round, the ids of the matches in
        // bracket-position order (to route losers into the LB later) and
        // whether each match's "loser" is real or a bye passthrough (a bye
        // never drops into the LB — nobody actually lost).
        $wbRoundIds = [];

        $roundMatchIds = [];
        $matchesInRound = intdiv($size, 2);

        for ($i = 0; $i < $matchesInRound; $i++) {
            $seedA = $order[2 * $i];
            $seedB = $order[2 * $i + 1];

            $matches[$id] = new BracketMatch(
                id: $id,
                round: 1,
                bracket: Bracket::Winners,
                position: $i + 1,
                slot1: $slotForSeed($seedA),
                slot2: $slotForSeed($seedB),
                nextMatch: null,
                nextSlot: null,
                loserNextMatch: null,
                loserNextSlot: null,
            );

            $roundMatchIds[] = $id;
            $id++;
        }

        $wbRoundIds[] = $roundMatchIds;
        $wbRound = 2;
        $previousRoundIds = $roundMatchIds;

        while (count($previousRoundIds) > 1) {
            $matchesInRound = intdiv(count($previousRoundIds), 2);
            $currentRoundIds = [];

            for ($i = 0; $i < $matchesInRound; $i++) {
                $feederA = $previousRoundIds[2 * $i];
                $feederB = $previousRoundIds[2 * $i + 1];

                $matches[$id] = new BracketMatch(
                    id: $id,
                    round: $wbRound,
                    bracket: Bracket::Winners,
                    position: $i + 1,
                    slot1: Slot::pendingFrom($feederA),
                    slot2: Slot::pendingFrom($feederB),
                    nextMatch: null,
                    nextSlot: null,
                    loserNextMatch: null,
                    loserNextSlot: null,
                );

                $matches[$feederA] = $matches[$feederA]->withRouting(nextMatch: $id, nextSlot: 1);
                $matches[$feederB] = $matches[$feederB]->withRouting(nextMatch: $id, nextSlot: 2);

                $currentRoundIds[] = $id;
                $id++;
            }

            $wbRoundIds[] = $currentRoundIds;
            $previousRoundIds = $currentRoundIds;
            $wbRound++;
        }

        // --- Losers bracket ---
        // wbRoundIds[k-1] holds WB round k's match ids in bracket-position
        // order. A match whose slot1/slot2 contains a bye produces a bye
        // "loser" (nobody really lost) — it must not occupy an LB intake
        // slot; the corresponding LB slot is simply left unfilled (empty)
        // and the WB match records no loserNextMatch/loserNextSlot.
        $isByeMatch = static fn (BracketMatch $m): bool => $m->slot1->isBye() || $m->slot2->isBye();

        $lbRound = 1;
        $lbRoundIds = [];

        // Degenerate case: a WB of exactly one round (n=2, i.e.
        // wbRoundCount === 1) has zero LB rounds
        // (2*(log2(2)-1) = 0) — there is no one for the WB final's loser to
        // play in the LB at all. The WB final's loser IS the LB champion
        // directly; $survivorIds stays empty and the WB-final/GF wiring
        // below routes the WB final's own loser into GF1 slot 2 instead of
        // an LB final.
        if ($wbRoundCount === 1) {
            $survivorIds = [];
        } else {
            // LB round 1 (major/intake): pair WB round 1's losers among
            // themselves, naturally ordered (no reordering needed for the
            // very first intake — there is no prior LB lineage yet to
            // avoid).
            $wbRound1Ids = $wbRoundIds[0];
            $intakeCount = count($wbRound1Ids);
            $currentRoundIds = [];

            for ($i = 0; $i < intdiv($intakeCount, 2); $i++) {
                $feederA = $wbRound1Ids[2 * $i];
                $feederB = $wbRound1Ids[2 * $i + 1];

                $matchId = $id++;

                // Both slots start empty regardless of whether the feeder was
                // a bye match — a bye "loser" simply never routes a loser
                // here at all (see the withRouting guards below), leaving the
                // slot empty until (if ever) a real loser is routed into it.
                $matches[$matchId] = new BracketMatch(
                    id: $matchId,
                    round: $lbRound,
                    bracket: Bracket::Losers,
                    position: $i + 1,
                    slot1: Slot::empty(),
                    slot2: Slot::empty(),
                    nextMatch: null,
                    nextSlot: null,
                    loserNextMatch: null,
                    loserNextSlot: null,
                );

                if (! $isByeMatch($matches[$feederA])) {
                    $matches[$feederA] = $matches[$feederA]->withRouting(loserNextMatch: $matchId, loserNextSlot: 1);
                }
                if (! $isByeMatch($matches[$feederB])) {
                    $matches[$feederB] = $matches[$feederB]->withRouting(loserNextMatch: $matchId, loserNextSlot: 2);
                }

                $currentRoundIds[] = $matchId;
            }

            $lbRoundIds[] = $currentRoundIds;
            $survivorIds = $currentRoundIds;
            $lbRound++;
        }

        // Remaining WB rounds (2..wbRoundCount) each drop their losers into
        // an intake (major) LB round; every intake round except the very
        // last (which pairs 1 WB loser against 1 LB survivor, no ordering
        // needed) is preceded by an internal (minor) halving round once the
        // survivor count from a *previous* intake needs reducing to match
        // the next batch's size.
        for ($k = 2; $k <= $wbRoundCount; $k++) {
            $wbLoserRoundIds = $wbRoundIds[$k - 1];
            $intakeSize = count($wbLoserRoundIds);

            // Internal (minor) halving round(s): reduce the current survivor
            // count down to the incoming batch size, one halving round at a
            // time (only needed when the survivor count exceeds the batch).
            while (count($survivorIds) > $intakeSize) {
                $currentRoundIds = [];

                for ($i = 0; $i < intdiv(count($survivorIds), 2); $i++) {
                    $feederA = $survivorIds[2 * $i];
                    $feederB = $survivorIds[2 * $i + 1];

                    $matchId = $id++;

                    $matches[$matchId] = new BracketMatch(
                        id: $matchId,
                        round: $lbRound,
                        bracket: Bracket::Losers,
                        position: $i + 1,
                        slot1: Slot::pendingFrom($feederA),
                        slot2: Slot::pendingFrom($feederB),
                        nextMatch: null,
                        nextSlot: null,
                        loserNextMatch: null,
                        loserNextSlot: null,
                    );

                    $matches[$feederA] = $matches[$feederA]->withRouting(nextMatch: $matchId, nextSlot: 1);
                    $matches[$feederB] = $matches[$feederB]->withRouting(nextMatch: $matchId, nextSlot: 2);

                    $currentRoundIds[] = $matchId;
                }

                $lbRoundIds[] = $currentRoundIds;
                $survivorIds = $currentRoundIds;
                $lbRound++;
            }

            // Intake (major) round: zip the reordered WB losers against the
            // current LB survivors.
            $orderedWbLoserIds = $this->lbIntakeOrder($size, $k, $wbLoserRoundIds);
            $currentRoundIds = [];

            for ($i = 0; $i < $intakeSize; $i++) {
                $wbFeeder = $orderedWbLoserIds[$i];
                $lbFeeder = $survivorIds[$i];

                $matchId = $id++;
                $wbFeederIsBye = $isByeMatch($matches[$wbFeeder]);

                // Slot 1 awaits the WB loser (left empty if it turns out to
                // be a bye passthrough — nobody really lost, so nobody is
                // ever routed here); slot 2 is pending the LB survivor.
                $matches[$matchId] = new BracketMatch(
                    id: $matchId,
                    round: $lbRound,
                    bracket: Bracket::Losers,
                    position: $i + 1,
                    slot1: Slot::empty(),
                    slot2: Slot::pendingFrom($lbFeeder),
                    nextMatch: null,
                    nextSlot: null,
                    loserNextMatch: null,
                    loserNextSlot: null,
                );

                if (! $wbFeederIsBye) {
                    $matches[$wbFeeder] = $matches[$wbFeeder]->withRouting(loserNextMatch: $matchId, loserNextSlot: 1);
                }
                $matches[$lbFeeder] = $matches[$lbFeeder]->withRouting(nextMatch: $matchId, nextSlot: 2);

                $currentRoundIds[] = $matchId;
            }

            $lbRoundIds[] = $currentRoundIds;
            $survivorIds = $currentRoundIds;
            $lbRound++;
        }

        // If there was an LB at all, survivorIds now holds exactly one match:
        // the LB final, whose winner is the losers-bracket champion. In the
        // degenerate n=2 case (zero LB rounds, see above) there is no LB
        // final — the WB final's own loser feeds GF1 slot 2 directly.
        $lbFinalId = $survivorIds[0] ?? null;

        // --- Winners-bracket final ---
        $wbFinalId = $wbRoundIds[$wbRoundCount - 1][0];

        // --- Grand final (GF1) + conditional reset (GF2) ---
        $gf1Id = $id++;
        $gf2Id = $id++;

        $matches[$gf1Id] = new BracketMatch(
            id: $gf1Id,
            round: 1,
            bracket: Bracket::Finals,
            position: 1,
            slot1: Slot::pendingFrom($wbFinalId),
            slot2: $lbFinalId !== null ? Slot::pendingFrom($lbFinalId) : Slot::empty(),
            nextMatch: $gf2Id,
            nextSlot: 1,
            loserNextMatch: null,
            loserNextSlot: null,
        );

        $matches[$gf2Id] = new BracketMatch(
            id: $gf2Id,
            round: 2,
            bracket: Bracket::Finals,
            position: 1,
            slot1: Slot::pendingFrom($gf1Id),
            slot2: Slot::pendingFrom($gf1Id),
            nextMatch: null,
            nextSlot: null,
            loserNextMatch: null,
            loserNextSlot: null,
        );

        $matches[$wbFinalId] = $matches[$wbFinalId]->withRouting(nextMatch: $gf1Id, nextSlot: 1);

        if ($lbFinalId !== null) {
            $matches[$lbFinalId] = $matches[$lbFinalId]->withRouting(nextMatch: $gf1Id, nextSlot: 2);
        } else {
            // No LB final exists: the WB final's loser drops directly into
            // GF1 slot 2, exactly like a WB loser dropping into the LB in
            // the general case — just routed straight to the grand final.
            $matches[$wbFinalId] = $matches[$wbFinalId]->withRouting(loserNextMatch: $gf1Id, loserNextSlot: 2);
        }

        return new BracketPlan($matches);
    }

    /**
     * The fixed per-size ordering applied to a batch of WB round-`k` losers
     * before they are zipped index-for-index against the current LB
     * survivors. This mirrors the "default minor ordering" table used by
     * mainstream double-elimination bracket generators (traced against a
     * production reference implementation and cross-checked by exhaustive
     * simulation during development): per bracket size, a fixed sequence of
     * orderings (natural / reverse / reverse-half-shift / ...) is applied at
     * each successive intake round so a dropped WB loser does not
     * immediately re-meet an opponent from the same recent bracket lineage.
     *
     * Only sizes 4, 8 and 16 are covered here — the sizes this engine is
     * exhaustively tested against (n=6 pads to size 8). Extending to size 32
     * and beyond requires adding further table entries (and matching test
     * coverage) rather than assuming this generalizes.
     *
     * `$k` is the WB round number (2-indexed WB rounds only — WB round 1's
     * losers are always paired among themselves naturally, never reordered
     * by this method).
     *
     * @param  array<int, int>  $wbLoserRoundIds  WB round-k match ids, in bracket-position order
     * @return array<int, int> the same ids, reordered
     */
    private function lbIntakeOrder(int $size, int $k, array $wbLoserRoundIds): array
    {
        if (! in_array($size, [4, 8, 16], true)) {
            throw new InvalidArgumentException(
                "Double elimination LB intake ordering is only defined for size 4, 8 or 16, got {$size}.",
            );
        }

        // The last WB round (the WB final) always drops a single loser —
        // there is nothing to reorder.
        if (count($wbLoserRoundIds) <= 1) {
            return $wbLoserRoundIds;
        }

        // Per-size ordering table (index 0 = first intake round after LB
        // round 1, i.e. WB round k=2; index 1 = the next intake round,
        // k=3; and so on):
        //   size 4:  ['reverse']                     (k=2 only)
        //   size 8:  ['reverse']                      (k=2 only)
        //   size 16: ['reverse_half_shift', 'reverse'] (k=2, then k=3)
        return match (true) {
            $size === 16 && $k === 2 => $this->reverseHalfShift($wbLoserRoundIds),
            default => array_reverse($wbLoserRoundIds),
        };
    }

    /**
     * Reverses each half of the array in place, keeping the two halves
     * where they are (e.g. [1,2,3,4] -> [2,1,4,3]).
     *
     * @param  array<int, int>  $array
     * @return array<int, int>
     */
    private function reverseHalfShift(array $array): array
    {
        $half = intdiv(count($array), 2);

        return array_merge(
            array_reverse(array_slice($array, 0, $half)),
            array_reverse(array_slice($array, $half)),
        );
    }

    /**
     * Generates a round-robin schedule: every entry plays every other entry
     * exactly once. Uses the circle method — one entry is fixed, the rest
     * rotate around it each round. Odd participant counts get a "ghost" bye
     * seat so every round has one participant sitting out; the round the
     * ghost is paired against is not recorded as a match (the plan's match
     * count is always exactly `n*(n-1)/2`).
     *
     * Matches never progress anywhere (`bracket = Winners`, no `nextMatch`);
     * standings are derived by counting wins, not by chaining matches
     * forward. See {@see BracketPlan} for why this is exempt from the
     * single-elimination "exactly one final match" invariant.
     *
     * @param  array<int, int>  $entryIds  already seeded, in order (order only affects circle rotation, not fairness)
     */
    public function roundRobin(array $entryIds): BracketPlan
    {
        $n = count($entryIds);

        if ($n < 2) {
            throw new InvalidArgumentException('Round robin requires at least 2 entries.');
        }

        // Circle method needs an even number of "seats"; odd n gets a bye
        // ghost seat so every round has exactly one participant sitting out.
        $hasGhost = $n % 2 !== 0;
        $seats = $hasGhost ? array_merge($entryIds, [null]) : $entryIds;
        $seatCount = count($seats);
        $rounds = $seatCount - 1;

        $matches = [];
        $id = 1;

        // Fixed seat stays at index 0; the rest rotate one position per round.
        $rotating = array_slice($seats, 1);

        for ($round = 1; $round <= $rounds; $round++) {
            $roundSeats = array_merge([$seats[0]], $rotating);
            $matchesInRound = intdiv($seatCount, 2);
            $position = 1;

            for ($i = 0; $i < $matchesInRound; $i++) {
                $seatA = $roundSeats[$i];
                $seatB = $roundSeats[$seatCount - 1 - $i];

                // The ghost's pairing is this round's bye: nobody plays, so
                // no match is recorded for it.
                if ($seatA === null || $seatB === null) {
                    continue;
                }

                $matches[$id] = new BracketMatch(
                    id: $id,
                    round: $round,
                    bracket: Bracket::Winners,
                    position: $position,
                    slot1: Slot::entry($seatA),
                    slot2: Slot::entry($seatB),
                    nextMatch: null,
                    nextSlot: null,
                    loserNextMatch: null,
                    loserNextSlot: null,
                );

                $id++;
                $position++;
            }

            // Rotate: move the last rotating seat to the front of the rest.
            array_unshift($rotating, array_pop($rotating));
        }

        return new BracketPlan($matches);
    }

    /**
     * Standard bracket seeding sequence for a bracket of the given size
     * (a power of two), recursively derived so that top seeds meet as late
     * as possible: [1,2] -> [1,4,3,2] -> [1,8,5,4,3,6,7,2] -> ...
     *
     * @return array<int, int> 1-indexed seed numbers in bracket slot order
     */
    private function seedOrder(int $size): array
    {
        $sequence = [1, 2];

        while (count($sequence) < $size) {
            $next = [];
            $currentSize = count($sequence) * 2;

            foreach ($sequence as $pos) {
                $next[] = $pos;
                $next[] = $currentSize + 1 - $pos;
            }

            $sequence = $next;
        }

        return $sequence;
    }

    /**
     * Smallest power of two greater than or equal to $n.
     */
    private function nextPowerOfTwo(int $n): int
    {
        $size = 1;

        while ($size < $n) {
            $size *= 2;
        }

        return $size;
    }
}
