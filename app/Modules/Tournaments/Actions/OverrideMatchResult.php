<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Policies\TournamentPolicy;
use App\Modules\Tournaments\Support\MatchProgression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An orga overrides a match's result directly — bypassing the
 * submit/confirm handshake entirely. Used to resolve a `Disputed` match, or
 * to correct a mistaken confirmation. Requires the `manage` ability on the
 * match's tournament (see {@see TournamentPolicy}).
 *
 * Runs the same bracket progression as {@see ConfirmMatchReport} — the
 * override is not a separate code path once the result is written, it just
 * arrives at "a decided result" without a confirmed report.
 */
class OverrideMatchResult
{
    public function __construct(
        private readonly MatchProgression $progression,
    ) {}

    public function handle(GameMatch $match, int $score1, int $score2): GameMatch
    {
        $tournament = Tournament::query()->findOrFail($match->tournament_id);

        Gate::authorize('manage', $tournament);

        return DB::transaction(function () use ($match, $tournament, $score1, $score2): GameMatch {
            $winnerEntryId = $score1 > $score2 ? $match->entry1_id : $match->entry2_id;

            $match->score1 = $score1;
            $match->score2 = $score2;
            $match->winner_entry_id = $winnerEntryId;
            $match->status = MatchStatus::Completed;
            $match->lock_version++;
            $match->save();

            $this->progression->apply($tournament, $match, $score1, $score2);

            $match->refresh();

            return $match;
        });
    }
}
