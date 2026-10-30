<?php

namespace App\Modules\Tournaments\Support;

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Collection;

/**
 * Read model for the participant team picker on team tournaments
 * (`team_size > 1`): the viewer's own teams (owner only — `TeamPolicy::update`
 * is what the enroll endpoint authorizes) and whether each currently has the
 * roster size `EnrollTeam` demands. Solo tournaments get null (one-click).
 */
final class TeamPicker
{
    /**
     * @return Collection<int, array{id: int, name: string, tag: string, memberCount: int<0, max>}> empty for guests
     */
    public static function ownedTeams(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        return Team::query()
            ->where('owner_id', $user->id)
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'tag' => $team->tag,
                'memberCount' => (int) $team->members_count,
            ]);
    }

    /**
     * @param  Collection<int, array{id: int, name: string, tag: string, memberCount: int<0, max>}>  $ownedTeams  from {@see ownedTeams()}
     * @return array{teamSize: int, teams: list<array{id: int, name: string, tag: string, memberCount: int, eligible: bool}>, createUrl: string}|null
     */
    public static function for(Tournament $tournament, Collection $ownedTeams): ?array
    {
        if ($tournament->team_size <= 1) {
            return null;
        }

        return [
            'teamSize' => $tournament->team_size,
            'teams' => array_values($ownedTeams
                ->map(fn (array $team): array => [...$team, 'eligible' => $team['memberCount'] === $tournament->team_size])
                ->all()),
            'createUrl' => route('teams.index'),
        ];
    }
}
