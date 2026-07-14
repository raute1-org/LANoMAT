<?php

namespace App\Modules\Tournaments\Support;

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Domain\BracketMatch;
use App\Modules\Tournaments\Domain\BracketPlan;
use App\Modules\Tournaments\Domain\BracketProgressor;
use App\Modules\Tournaments\Domain\Slot;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * Bridges the pure-domain bracket engine and persistence for a *played*
 * result — the mirror-image of {@see BracketPersister}, which bridges it for
 * the initial bracket generation.
 *
 * Given a `GameMatch` that was just decided (a confirmed report or an orga
 * override), this:
 *
 *  1. Reconstructs a {@see BracketPlan} from every `GameMatch` row belonging
 *     to the same tournament, using each row's own id as the domain match
 *     id — so the routing (`nextMatch`/`loserNextMatch`) already encoded on
 *     the rows maps 1:1 onto the domain plan, no id-translation needed.
 *  2. Applies the result via {@see BracketProgressor::apply()}, which
 *     returns a new plan with the winner/loser propagated and any
 *     now-resolvable byes/dead slots auto-advanced.
 *  3. Diffs every domain match against its persisted counterpart and writes
 *     only the rows that actually changed.
 *  4. Dispatches `MatchCompleted` for the reported match, `MatchReady` for
 *     every match that just became playable, and — if the tournament's
 *     final match is now decided — sets `Tournament::$winner_entry_id` and
 *     `status = Finished` and dispatches `TournamentCompleted`.
 *
 * The domain engine itself never touches the database; this class is the
 * only place that knows both worlds.
 */
class MatchProgression
{
    public function __construct(
        private readonly BracketProgressor $progressor,
    ) {}

    public function apply(Tournament $tournament, GameMatch $match, int $score1, int $score2): void
    {
        /** @var Collection<int, GameMatch> $rows */
        $rows = GameMatch::query()->where('tournament_id', $tournament->id)->get()->keyBy('id');

        // Snapshot each row's pre-change status before diffAndPersist() below
        // mutates these same model instances in place — needed to detect a
        // Pending/Reported -> Ready transition afterwards.
        /** @var array<int, MatchStatus> $statusBefore */
        $statusBefore = $rows->map(fn (GameMatch $row): MatchStatus => $row->status)->all();

        $plan = $this->reconstruct($rows);
        $plan = $this->progressor->apply($plan, $match->id, $score1, $score2);

        $this->diffAndPersist($rows, $plan);

        Event::dispatch(new MatchCompleted(GameMatch::findOrFail($match->id)));

        foreach ($plan->matches() as $domainMatch) {
            if (! array_key_exists($domainMatch->id, $statusBefore) || $domainMatch->id === $match->id) {
                continue;
            }

            $wasReady = $statusBefore[$domainMatch->id] === MatchStatus::Ready;
            $isNowReady = $domainMatch->isPlayable() && ! $domainMatch->isDecided();

            if ($isNowReady && ! $wasReady) {
                Event::dispatch(new MatchReady(GameMatch::findOrFail($domainMatch->id)));
            }
        }

        $this->detectCompletion($tournament, $plan);
    }

    /**
     * @param  Collection<int, GameMatch>  $rows
     */
    private function reconstruct(Collection $rows): BracketPlan
    {
        $matches = [];

        foreach ($rows as $row) {
            $matches[$row->id] = new BracketMatch(
                id: $row->id,
                round: $row->round,
                bracket: Bracket::from($row->bracket),
                position: $row->position,
                slot1: $this->slotFor($row->entry1_id),
                slot2: $this->slotFor($row->entry2_id),
                nextMatch: $row->next_match_id,
                nextSlot: $row->next_slot,
                loserNextMatch: $row->loser_match_id,
                loserNextSlot: $row->loser_slot,
                score1: $row->score1,
                score2: $row->score2,
                winnerSlot: $this->winnerSlotFor($row),
            );
        }

        return new BracketPlan($matches);
    }

    private function slotFor(?int $entryId): Slot
    {
        return $entryId !== null ? Slot::entry($entryId) : Slot::empty();
    }

    private function winnerSlotFor(GameMatch $row): ?int
    {
        if ($row->winner_entry_id === null) {
            return null;
        }

        return match ($row->winner_entry_id) {
            $row->entry1_id => 1,
            $row->entry2_id => 2,
            default => null,
        };
    }

    /**
     * @param  Collection<int, GameMatch>  $rows
     */
    private function diffAndPersist(Collection $rows, BracketPlan $plan): void
    {
        foreach ($plan->matches() as $domainMatch) {
            $row = $rows->get($domainMatch->id);

            if ($row === null) {
                continue;
            }

            $entry1Id = $domainMatch->slot1->entryId();
            $entry2Id = $domainMatch->slot2->entryId();
            $winnerEntryId = $domainMatch->winnerEntryId();
            $status = $this->statusFor($domainMatch);

            $changed = $row->entry1_id !== $entry1Id
                || $row->entry2_id !== $entry2Id
                || $row->score1 !== $domainMatch->score1
                || $row->score2 !== $domainMatch->score2
                || $row->winner_entry_id !== $winnerEntryId
                || $row->status !== $status;

            if (! $changed) {
                continue;
            }

            $row->forceFill([
                'entry1_id' => $entry1Id,
                'entry2_id' => $entry2Id,
                'score1' => $domainMatch->score1,
                'score2' => $domainMatch->score2,
                'winner_entry_id' => $winnerEntryId,
                'status' => $status,
            ])->save();
        }
    }

    private function statusFor(BracketMatch $match): MatchStatus
    {
        if ($match->isDecided()) {
            return MatchStatus::Completed;
        }

        if ($match->isPlayable()) {
            return MatchStatus::Ready;
        }

        return MatchStatus::Pending;
    }

    /**
     * Single elimination has no `Bracket::Finals` matches at all — its final
     * is simply the last `Bracket::Winners` match, i.e. `BracketPlan`'s sole
     * terminal match (`finalMatch()`); once it is decided the tournament is
     * over.
     *
     * Double elimination has exactly two `Finals` matches — GF1 and the
     * reset GF2 — chained via `nextMatch` (GF1 -> GF2), of which only one
     * ever actually ends the tournament, per
     * `BracketProgressor::propagate()`'s grand-final-reset rule:
     *
     *  - If the winners-bracket side (slot 1) wins GF1 outright, the reset
     *    is left dead — never armed, never decided. GF1 itself ends the
     *    tournament and its winner is the champion.
     *  - If the losers-bracket side (slot 2) wins GF1, the reset (GF2) is
     *    armed and must be played; only once GF2 is decided is the
     *    tournament over, and GF2's winner is the champion. GF1 being
     *    decided at this point must NOT be mistaken for tournament-ending —
     *    that would freeze the wrong (GF1) winner as champion and, since
     *    completion is idempotent per tournament, permanently ignore GF2's
     *    real result.
     *
     * So a decided `Finals` match ends the tournament when either it has no
     * `nextMatch` (GF2, or the plan's terminal match in general), or its
     * `nextMatch` chains to another `Finals` match but slot 1 (winners-
     * bracket side) won — the reset-armed case is explicitly excluded.
     *
     * Round-robin plans are exempt (see {@see BracketPlan}'s own progressing-
     * plan invariant): every match there has `nextMatch === null` by design,
     * since standings are derived by counting wins rather than by chaining
     * matches forward, so this bracket-progression completion detection does
     * not apply to them at all.
     */
    private function detectCompletion(Tournament $tournament, BracketPlan $plan): void
    {
        if ($tournament->status === TournamentStatus::Finished) {
            return;
        }

        $isProgressing = collect($plan->matches())->contains(fn (BracketMatch $m): bool => $m->nextMatch !== null);

        if (! $isProgressing) {
            return;
        }

        $champion = collect($plan->matches())->first(fn (BracketMatch $m): bool => $this->endsTournament($plan, $m));

        if ($champion === null) {
            return;
        }

        $championEntryId = $champion->winnerEntryId();

        if ($championEntryId === null) {
            return;
        }

        $tournament->winner_entry_id = $championEntryId;
        $tournament->status = TournamentStatus::Finished;
        $tournament->save();

        Event::dispatch(new TournamentCompleted($tournament));
    }

    private function endsTournament(BracketPlan $plan, BracketMatch $match): bool
    {
        if (! $match->isDecided()) {
            return false;
        }

        if ($match->nextMatch === null) {
            return true;
        }

        // A decided match with a next match only ends the tournament if that
        // next match is itself another Finals match (the grand-final reset)
        // AND the winners-bracket side won outright, leaving the reset dead.
        return $match->bracket === Bracket::Finals
            && $plan->match($match->nextMatch)->bracket === Bracket::Finals
            && $match->winnerSlot === 1;
    }
}
