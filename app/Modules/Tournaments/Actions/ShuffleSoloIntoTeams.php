<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Teams\Actions\CreateTeam;
use App\Modules\Teams\Enums\TeamRole;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * For "solo-team" tournaments (`settings['auto_team']`, as in v1): shuffles
 * solo entries into ad-hoc teams of `team_size`, replacing the original solo
 * entries with new team entries carrying a `roster_snapshot`. The original
 * solo entries are withdrawn (not deleted) so their history is preserved.
 *
 * Each ad-hoc team is a real, internally-consistent `Team` row (owner +
 * `TeamMember` rows for every shuffled participant) built via
 * {@see CreateTeam} - required because `tournament_entries` has a DB CHECK
 * enforcing exactly one of `team_id`/`user_id`, so a shuffled TEAM entry
 * must reference a real `team_id`.
 */
class ShuffleSoloIntoTeams
{
    public function __construct(
        private readonly CreateTeam $createTeam,
    ) {}

    /**
     * @param  Collection<int, TournamentEntry>  $entries  the participating solo entries
     * @return Collection<int, TournamentEntry> the new ad-hoc team entries
     */
    public function handle(Tournament $tournament, Collection $entries): Collection
    {
        $teamSize = $tournament->team_size;

        if ($teamSize < 1) {
            throw new InvalidArgumentException('team_size must be at least 1 for auto_team shuffling.');
        }

        $randomizer = new Randomizer(new Mt19937($this->seedFor($tournament)));

        /** @var array<int, TournamentEntry> $shuffled */
        $shuffled = $randomizer->shuffleArray($entries->all());

        $teamEntries = collect();

        foreach (array_chunk($shuffled, $teamSize) as $index => $chunk) {
            $roster = collect($chunk)->map(function (TournamentEntry $entry): array {
                // Solo entries always carry a user_id (DB check constraint:
                // exactly one of team_id/user_id is set); a null here would
                // mean this "solo" entry is actually a team entry, which
                // ShuffleSoloIntoTeams must never be called with.
                $userId = $entry->user_id
                    ?? throw new LogicException("Solo entry {$entry->id} unexpectedly has no user_id.");

                return [
                    'user_id' => $userId,
                    'name' => $entry->display_name,
                ];
            })->all();

            $owner = $chunk[0]->user
                ?? throw new LogicException("Solo entry {$chunk[0]->id} unexpectedly has no user_id.");

            $team = $this->createTeam->handle(
                $owner,
                __('tournaments.auto_team.name', ['number' => $index + 1]),
                $this->uniqueTag($tournament, $index),
            );

            foreach (array_slice($chunk, 1) as $member) {
                $userId = $member->user_id
                    ?? throw new LogicException("Solo entry {$member->id} unexpectedly has no user_id.");

                $teamMember = $team->members()->make(['user_id' => $userId]);
                $teamMember->role = TeamRole::Member;
                $teamMember->save();
            }

            $teamEntry = new TournamentEntry([
                'tournament_id' => $tournament->id,
                'team_id' => $team->id,
                'display_name' => $team->name,
            ]);
            $teamEntry->status = EntryStatus::CheckedIn;
            $teamEntry->checked_in_at = Carbon::now();
            $teamEntry->roster_snapshot = $roster;
            $teamEntry->save();

            $teamEntries->push($teamEntry);

            foreach ($chunk as $soloEntry) {
                $soloEntry->status = EntryStatus::Withdrawn;
                $soloEntry->save();
            }
        }

        return $teamEntries;
    }

    private function seedFor(Tournament $tournament): int
    {
        return crc32('tournament-auto-team-'.$tournament->getKey());
    }

    /**
     * Ad-hoc team tags have no DB uniqueness constraint, but collisions
     * should still be avoided in practice. Derive a tag deterministically
     * from the tournament and chunk index, falling back to a short random
     * suffix retry loop in the astronomically unlikely event of a collision
     * with an existing team's tag.
     */
    private function uniqueTag(Tournament $tournament, int $index): string
    {
        $base = strtoupper(substr(sprintf('T%d%d', $tournament->getKey(), $index + 1), 0, 16));

        $tag = $base;

        while (Team::where('tag', $tag)->exists()) {
            $tag = strtoupper(substr($base, 0, 12).Str::random(4));
        }

        return $tag;
    }
}
