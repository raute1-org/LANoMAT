<?php

namespace App\Modules\Tournaments\Support;

use App\Modules\Tournaments\Domain\BracketMatch;
use App\Modules\Tournaments\Domain\BracketPlan;
use App\Modules\Tournaments\Domain\BracketProgressor;
use App\Modules\Tournaments\Domain\Slot;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;

/**
 * Persists a pure-domain {@see BracketPlan} as `GameMatch` rows for a
 * tournament. Two passes:
 *
 *  1. Create one `GameMatch` per domain match, capturing a
 *     domain-match-id -> persisted-GameMatch-id map. Slots resolve to
 *     `entry1_id`/`entry2_id` (a `Slot::entry`), or null (pending/empty).
 *  2. Re-visit every created row and set `next_match_id`/`next_slot` and
 *     `loser_match_id`/`loser_slot` by translating the domain plan's
 *     `nextMatch`/`loserNextMatch` ids through the map from pass 1 — the
 *     domain ids are meaningless once persisted, only the map ties them
 *     together.
 *
 * Byes: before persisting, the plan is first run through
 * {@see BracketProgressor}'s auto-advance resolution so every bye (and any
 * resulting chain of byes) is already "played" in the domain plan. A bye
 * match is therefore persisted as `Completed` with `winner_entry_id` set to
 * the real entrant, and that same entrant is already written into the
 * downstream match's slot — there is never an open/playable bye match after
 * persistence.
 */
class BracketPersister
{
    public function __construct(
        private readonly BracketProgressor $progressor,
    ) {}

    public function persist(Tournament $tournament, BracketPlan $plan): void
    {
        $plan = $this->resolveByes($plan);

        /** @var array<int, int> $idMap domain match id => persisted GameMatch id */
        $idMap = [];

        // Pass 1: create rows, resolve slot entry ids.
        foreach ($plan->matches() as $domainMatch) {
            $gameMatch = new GameMatch([
                'tournament_id' => $tournament->id,
                'round' => $domainMatch->round,
                'bracket' => $domainMatch->bracket->value,
                'position' => $domainMatch->position,
                'entry1_id' => $domainMatch->slot1->entryId(),
                'entry2_id' => $domainMatch->slot2->entryId(),
            ]);

            $gameMatch->status = $this->statusFor($domainMatch);

            if ($domainMatch->isDecided()) {
                // A "decided" match at persist time is always a bye
                // auto-advance (see resolveByes()) — nobody actually played,
                // so no score is recorded, only the winner.
                $gameMatch->winner_entry_id = $domainMatch->winnerEntryId();
            }

            $gameMatch->save();

            $idMap[$domainMatch->id] = $gameMatch->id;
        }

        // Pass 2: link routing fields through the id map.
        foreach ($plan->matches() as $domainMatch) {
            $updates = [];

            if ($domainMatch->nextMatch !== null) {
                $updates['next_match_id'] = $idMap[$domainMatch->nextMatch];
                $updates['next_slot'] = $domainMatch->nextSlot;
            }

            if ($domainMatch->loserNextMatch !== null) {
                $updates['loser_match_id'] = $idMap[$domainMatch->loserNextMatch];
                $updates['loser_slot'] = $domainMatch->loserNextSlot;
            }

            if ($updates !== []) {
                GameMatch::query()->whereKey($idMap[$domainMatch->id])->update($updates);
            }
        }
    }

    /**
     * Auto-resolves every bye (and any resulting chain of byes) in the plan
     * before persistence, by "playing" each bye match with a synthetic 1-0
     * result via the progressor. This reuses the exact same auto-advance
     * logic the progressor already applies during live play, so bye
     * resolution at start-time and bye resolution mid-tournament (e.g. a WB
     * round-1 bye whose downstream match later also turns out to be a bye)
     * can never disagree.
     */
    private function resolveByes(BracketPlan $plan): BracketPlan
    {
        // Each apply() call already recursively auto-resolves every
        // now-resolvable bye/dead slot across the whole plan (see
        // BracketProgressor::resolveAutoAdvances), so one pass per
        // originally-bye match is enough to reach a fixed point; we just
        // re-scan because playing one bye can turn a previously-undecided,
        // non-bye-looking match into a bye (chained byes).
        do {
            $changed = false;

            foreach ($plan->matches() as $match) {
                if ($match->isDecided() || ! $this->isBye($match)) {
                    continue;
                }

                $winnerSlot = $match->slot1->isEntry() ? 1 : 2;
                [$score1, $score2] = $winnerSlot === 1 ? [1, 0] : [0, 1];

                $plan = $this->progressor->apply($plan, $match->id, $score1, $score2);
                $changed = true;
            }
        } while ($changed);

        return $plan;
    }

    private function isBye(BracketMatch $match): bool
    {
        return ($match->slot1->isEntry() && $match->slot2->isBye())
            || ($match->slot2->isEntry() && $match->slot1->isBye());
    }

    private function statusFor(BracketMatch $match): MatchStatus
    {
        if ($match->isDecided()) {
            return MatchStatus::Completed;
        }

        if ($this->isRealEntry($match->slot1) && $this->isRealEntry($match->slot2)) {
            return MatchStatus::Ready;
        }

        return MatchStatus::Pending;
    }

    private function isRealEntry(Slot $slot): bool
    {
        return $slot->isEntry();
    }
}
