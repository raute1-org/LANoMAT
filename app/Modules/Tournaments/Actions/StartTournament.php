<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Domain\BracketGenerator;
use App\Modules\Tournaments\Domain\BracketPlan;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\BracketPersister;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Bridges the pure-domain bracket engine and persistence: collects the
 * participating entries, optionally shuffles solo entries into ad-hoc teams,
 * seeds them, generates a {@see BracketPlan}
 * via {@see BracketGenerator}, persists it as `GameMatch` rows via
 * {@see BracketPersister}, and transitions the tournament to `Live`.
 *
 * Only `StartTournament` transitions a tournament to `Live` (see
 * `TournamentTickCommand`); double-start is impossible thanks to the status
 * guard plus the row lock below.
 *
 * Double-elimination brackets are only generated (and progressed) correctly
 * for participating-entry counts in {2, 4, 6, 8, 16} — see
 * `BracketGenerator::doubleElimination()`'s LB intake ordering table and
 * `BracketProgressor`'s convergence assumptions. Any other count throws
 * before the generator is invoked.
 */
class StartTournament
{
    private const SUPPORTED_DOUBLE_ELIMINATION_SIZES = [2, 4, 6, 8, 16];

    public function __construct(
        private readonly BracketGenerator $generator,
        private readonly BracketPersister $persister,
        private readonly ShuffleSoloIntoTeams $shuffleSoloIntoTeams,
        private readonly SeedEntries $seedEntries,
    ) {}

    public function handle(Tournament $tournament): Tournament
    {
        return DB::transaction(function () use ($tournament): Tournament {
            // Lock the tournament row first so a concurrent double-start
            // attempt serializes on this row before either reads the status
            // (same lock-order convention as EnrollSolo/EnrollTeam).
            $tournament = Tournament::query()->whereKey($tournament->getKey())->lockForUpdate()->firstOrFail();

            if ($tournament->status === TournamentStatus::Live) {
                throw TournamentException::alreadyStarted();
            }

            if (! in_array($tournament->status, [TournamentStatus::CheckIn, TournamentStatus::Enrollment], true)) {
                throw TournamentException::notInEnrollment();
            }

            $entries = $this->participatingEntries($tournament);

            if ($tournament->settings['auto_team'] ?? false) {
                $entries = $this->shuffleSoloIntoTeams->handle($tournament, $entries);
            }

            $entryIds = $this->seedEntries->handle($tournament, $entries);

            if ($tournament->format === TournamentFormat::DoubleElimination
                && ! in_array(count($entryIds), self::SUPPORTED_DOUBLE_ELIMINATION_SIZES, true)) {
                throw TournamentException::unsupportedDoubleEliminationSize(count($entryIds));
            }

            $plan = match ($tournament->format) {
                TournamentFormat::SingleElimination => $this->generator->singleElimination($entryIds),
                TournamentFormat::DoubleElimination => $this->generator->doubleElimination($entryIds),
                TournamentFormat::RoundRobin => $this->generator->roundRobin($entryIds),
            };

            $this->persister->persist($tournament, $plan);

            $tournament->status = TournamentStatus::Live;
            $tournament->save();

            Event::dispatch(new TournamentStarted($tournament));

            return $tournament;
        });
    }

    /**
     * The entries that take part in the bracket: only `CheckedIn` entries if
     * the tournament requires check-in (`settings['require_checkin']`,
     * default true — check-in is the norm), otherwise every non-withdrawn
     * `Registered`/`CheckedIn` entry.
     *
     * @return Collection<int, TournamentEntry>
     */
    private function participatingEntries(Tournament $tournament): Collection
    {
        $requireCheckin = $tournament->settings['require_checkin'] ?? true;

        $query = TournamentEntry::query()->where('tournament_id', $tournament->id);

        if ($requireCheckin) {
            $query->where('status', EntryStatus::CheckedIn->value);
        } else {
            $query->where('status', '!=', EntryStatus::Withdrawn->value);
        }

        return $query->get();
    }
}
