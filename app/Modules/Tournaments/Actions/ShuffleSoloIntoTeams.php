<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * For "solo-team" tournaments (`settings['auto_team']`, as in v1): shuffles
 * solo entries into ad-hoc teams of `team_size`, replacing the original solo
 * entries with new team entries carrying a `roster_snapshot`. The original
 * solo entries are withdrawn (not deleted) so their history is preserved.
 */
class ShuffleSoloIntoTeams
{
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

            $ownerId = $chunk[0]->user_id
                ?? throw new LogicException("Solo entry {$chunk[0]->id} unexpectedly has no user_id.");

            // owner_id is not mass-fillable on Team (set only via CreateTeam
            // normally); assign it explicitly, same as other actions do for
            // guarded fields (e.g. EntryStatus on TournamentEntry above).
            $team = new Team([
                'name' => __('tournaments.auto_team.name', ['number' => $index + 1]),
                'tag' => strtoupper(substr(md5(uniqid('', true)), 0, 3)),
            ]);
            $team->owner_id = $ownerId;
            $team->save();

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
}
