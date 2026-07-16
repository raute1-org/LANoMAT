<?php

namespace App\Modules\Infoscreen\Listeners;

use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\MatchProgression;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a decided match by checking whether it just crowned a tournament
 * champion — and if so, re-broadcasts a synthetic {@see SceneType::Winner}
 * scene via {@see SceneOverride} onto the tournament's *event* channel
 * (`event.{id}`), interrupting the beamer's rotation with a winner overlay.
 * `MatchCompleted` itself only rides the narrower `tournament.{id}` channel
 * (see its own docblock), so this listener is the bridge onto the wider
 * audience the beamer screen actually subscribes to.
 *
 * A match is decisive in one of two ways:
 *
 *  - It is itself the grand final: `bracket === Finals` with no further
 *    `next_match_id` (double-elimination's GF1-outright-win or GF2 reset —
 *    see {@see MatchProgression::detectCompletion()}
 *    for why "no next match" is the right terminal check there).
 *  - Or — the case that also covers single-elimination, whose terminal match
 *    is a plain `Winners`-bracket match, never `Finals` — the parent
 *    tournament has already been marked `Finished` with a `winner_entry_id`
 *    by the time this queued listener runs (`MatchProgression::apply()`
 *    persists that before `MatchCompleted` is dispatched, and both share the
 *    same after-commit transaction).
 *
 * Deliberately not idempotent: a re-fired `MatchCompleted` for an
 * already-decided final may re-show the overlay. Acceptable for a beamer
 * display — no user-facing harm, and not worth persisting dedup state for.
 */
class BroadcastWinnerMoment implements ShouldQueue
{
    private const DURATION_SEC = 12;

    public function handle(MatchCompleted $event): void
    {
        $match = $event->match->fresh(['tournament']);

        if ($match === null) {
            return;
        }

        $tournament = $match->tournament;

        if ($tournament === null) {
            return;
        }

        $championEntryId = $this->championEntryId($match, $tournament);

        if ($championEntryId === null) {
            return;
        }

        $champion = $match->tournament->entries()->find($championEntryId);

        if ($champion === null) {
            return;
        }

        SceneOverride::dispatch($tournament->event_id, [
            'type' => SceneType::Winner->value,
            'durationSec' => self::DURATION_SEC,
            'config' => [],
            'data' => [
                'winner' => $champion->display_name,
                'tournament' => $tournament->name,
            ],
        ]);
    }

    private function championEntryId(GameMatch $match, Tournament $tournament): ?int
    {
        $isDecisiveFinal = $match->bracket === Bracket::Finals->value && $match->next_match_id === null;

        if ($isDecisiveFinal && $match->winner_entry_id !== null) {
            return $match->winner_entry_id;
        }

        if ($tournament->status === TournamentStatus::Finished && $tournament->winner_entry_id !== null) {
            return $tournament->winner_entry_id;
        }

        return null;
    }
}
