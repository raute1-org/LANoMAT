<?php

namespace App\Modules\Tournaments\Actions;

use App\Models\User;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Events\MatchWentLive;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Policies\TournamentPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The beamer "gong" moment: an orga/helper (or, once both rosters have
 * checked in as ready, an automatic trigger — see
 * {@see allRostersReady()}) flips a `Warmup` match back to `Ready`
 * (live-ish — the match is now actually being played) and dispatches
 * {@see MatchWentLive}, which
 * `App\Modules\Infoscreen\Listeners\GongOnMatchLive` turns into a
 * synthetic beamer scene.
 *
 * Authorization only applies to the manual human trigger
 * ({@see TournamentPolicy::goLive},
 * helper-or-above, mirroring
 * {@see TournamentPolicy::setManualJoinInfo}):
 * `$actor` is required on every call (there is no anonymous/system caller
 * for this Action in this task), so a future automatic "all rosters ready"
 * trigger must still be invoked as some real user (e.g. a system/service
 * account) rather than bypassing the Gate — this keeps exactly one
 * authorization path for the state transition, matching
 * {@see SetManualJoinInfo}-style Actions
 * across the module.
 *
 * Row-locks the match (mirrors {@see ConfirmMatchReport}) so a concurrent
 * double-trigger of "Go" cannot fire {@see MatchWentLive} (and the gong)
 * twice for the same warmup.
 */
class GoLive
{
    public function handle(GameMatch $match, User $actor): GameMatch
    {
        Gate::forUser($actor)->authorize('goLive', $match);

        $updated = DB::transaction(function () use ($match): GameMatch {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Warmup) {
                throw TournamentException::matchNotInWarmup();
            }

            $locked->status = MatchStatus::Ready;
            $locked->save();

            return $locked;
        });

        MatchWentLive::dispatch($updated);

        return $updated;
    }

    /**
     * True once both of the match's entries have checked in — the
     * "all rosters ready" condition the brief names as an alternative
     * trigger to a human helper's manual "Go". Not yet wired to an automatic
     * caller in this task (no scheduler/listener invokes it); exposed so a
     * later task can call {@see handle()} on behalf of a system actor once
     * this is true, without duplicating the roster-readiness check.
     */
    public function allRostersReady(GameMatch $match): bool
    {
        $entry1Ready = $match->entry1 !== null && $match->entry1->status === EntryStatus::CheckedIn;
        $entry2Ready = $match->entry2 !== null && $match->entry2->status === EntryStatus::CheckedIn;

        return $entry1Ready && $entry2Ready;
    }
}
