<?php

namespace App\Modules\Teams\Support;

use App\Models\User;
use App\Modules\Teams\Models\TeamMember;

/**
 * Read model: the teams a user belongs to, with their role in each.
 */
class MyTeams
{
    /**
     * @return list<array{id: int, name: string, tag: string, role: string}>
     */
    public static function for(User $user): array
    {
        $teams = [];

        $members = TeamMember::query()
            ->where('user_id', $user->id)
            ->with('team')
            ->get();

        foreach ($members as $member) {
            $team = $member->team;

            if ($team === null) {
                continue;
            }

            $teams[] = [
                'id' => $team->id,
                'name' => $team->name,
                'tag' => $team->tag,
                'role' => $member->role->value,
            ];
        }

        usort($teams, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $teams;
    }
}
