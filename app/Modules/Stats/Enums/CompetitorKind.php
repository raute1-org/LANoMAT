<?php

declare(strict_types=1);

namespace App\Modules\Stats\Enums;

/**
 * A leaderboard competitor is either a `Team` or a `User` — never both
 * (`TournamentEntry` has a DB check constraint enforcing exactly one of
 * `team_id`/`user_id`). Used to key aggregates by composite identity so a
 * team is never conflated with one of its members.
 */
enum CompetitorKind: string
{
    case User = 'user';
    case Team = 'team';
}
