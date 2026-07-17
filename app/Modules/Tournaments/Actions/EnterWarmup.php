<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Policies\TournamentPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves a `Ready` match into `Warmup` — the game-agnostic gate between "both
 * slots filled" and "actually being played". Triggered either automatically
 * (the match's game server just came up, see the GameServers module's
 * server-ready listener) or manually by an orga/helper from the match page.
 *
 * Deliberately has no actor/authorization check itself: unlike
 * {@see GoLive}, entering warmup is never destructive
 * (nothing is decided, nobody's report is affected) and has two legitimate,
 * differently-shaped callers — an automatic listener with no human actor at
 * all, and a manual control that authorizes via
 * {@see TournamentPolicy::setManualJoinInfo}-style
 * Policy check at its own call site. Mirrors
 * {@see SubmitMatchReport}, which is
 * likewise guarded only by match status here, with identity/authorization
 * enforced by its caller.
 */
class EnterWarmup
{
    public function handle(GameMatch $match): GameMatch
    {
        return DB::transaction(function () use ($match): GameMatch {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Ready) {
                throw TournamentException::matchNotReady();
            }

            $locked->status = MatchStatus::Warmup;
            $locked->warmup_started_at = Carbon::now();
            $locked->save();

            return $locked;
        });
    }
}
