<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Domain\BracketGenerator;
use App\Modules\Tournaments\Domain\BracketPlan;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\BracketPersister;
use App\Modules\Tournaments\Support\TeamRosterGuard;
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
 * Every format needs at least 2 participating entries; both elimination
 * formats support 2 to 64 (counts that are not a power of two are padded with
 * byes). Anything outside that range throws a German {@see TournamentException}
 * before the generator is invoked.
 */
class StartTournament
{
    /** Every format needs a pairing: at least two participating entries. */
    private const MIN_ENTRIES = 2;

    /** The tested ceiling of both elimination formats; see LbIntakeOrdering. */
    private const MAX_ELIMINATION_ENTRIES = 64;

    public function __construct(
        private readonly BracketGenerator $generator,
        private readonly BracketPersister $persister,
        private readonly ShuffleSoloIntoTeams $shuffleSoloIntoTeams,
        private readonly SeedEntries $seedEntries,
        private readonly TeamRosterGuard $rosterGuard,
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

            // Team rosters may have changed since enrollment (F-R2A-5/6).
            $this->rosterGuard->revalidateAll($tournament, $entries);

            if ($tournament->settings['auto_team'] ?? false) {
                $entries = $this->shuffleSoloIntoTeams->handle($tournament, $entries);
            }

            $entryIds = $this->seedEntries->handle($tournament, $entries);

            if (count($entryIds) < self::MIN_ENTRIES) {
                throw TournamentException::notEnoughEntries(count($entryIds));
            }

            if (count($entryIds) > self::MAX_ELIMINATION_ENTRIES) {
                if ($tournament->format === TournamentFormat::DoubleElimination) {
                    throw TournamentException::unsupportedDoubleEliminationSize(count($entryIds));
                }

                if ($tournament->format === TournamentFormat::SingleElimination) {
                    throw TournamentException::unsupportedSingleEliminationSize(count($entryIds));
                }
            }

            $plan = match ($tournament->format) {
                TournamentFormat::SingleElimination => $this->generator->singleElimination($entryIds),
                TournamentFormat::DoubleElimination => $this->generator->doubleElimination($entryIds),
                TournamentFormat::RoundRobin => $this->generator->roundRobin($entryIds),
            };

            $persisted = $this->persister->persist($tournament, $plan);

            $tournament->status = TournamentStatus::Live;
            $tournament->save();

            Event::dispatch(new TournamentStarted($tournament));

            // The persister already writes MatchStatus::Ready directly onto
            // any round with both slots filled from the start (typically
            // every round-1 match) — those never pass through
            // MatchProgression's Pending/Reported -> Ready transition, so
            // MatchReady must be dispatched for them here or they never get
            // a Discord text channel / Mumble voice channel at all.
            $persisted
                ->filter(fn (GameMatch $match): bool => $match->status === MatchStatus::Ready)
                ->each(fn (GameMatch $match) => Event::dispatch(new MatchReady($match)));

            return $tournament;
        });
    }

    /**
     * How many entries would take part right now, before any auto-team
     * shuffle: the scheduler uses it to skip tournaments that cannot start
     * yet instead of dispatching a job that is bound to fail every minute.
     */
    public function participatingCount(Tournament $tournament): int
    {
        return $this->participatingEntries($tournament)->count();
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
