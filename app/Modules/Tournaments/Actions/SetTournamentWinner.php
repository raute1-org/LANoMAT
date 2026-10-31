<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Domain\RoundRobinStandings;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\MatchProgression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The orga names the winner of a round robin that ended with a tie for first
 * place (wins and head-to-head leave several entries level, see
 * {@see RoundRobinStandings}). The tournament stays live with every match
 * played until this decision, then finishes like any other.
 */
class SetTournamentWinner
{
    public function __construct(
        private readonly MatchProgression $progression,
    ) {}

    public function handle(Tournament $tournament, int $entryId): Tournament
    {
        Gate::authorize('manage', $tournament);

        return DB::transaction(function () use ($tournament, $entryId): Tournament {
            $tournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            if ($tournament->status !== TournamentStatus::Live || $tournament->format !== TournamentFormat::RoundRobin) {
                throw TournamentException::winnerNotDecidable();
            }

            $plan = $this->progression->reconstruct(
                GameMatch::query()->where('tournament_id', $tournament->id)->get()->keyBy('id'),
            );

            $leaders = RoundRobinStandings::leaders($plan);

            if (! RoundRobinStandings::isComplete($plan) || count($leaders) < 2 || ! in_array($entryId, $leaders, true)) {
                throw TournamentException::winnerNotDecidable();
            }

            $tournament->winner_entry_id = $entryId;
            $tournament->status = TournamentStatus::Finished;
            $tournament->save();

            TournamentCompleted::dispatch($tournament);

            return $tournament;
        });
    }
}
