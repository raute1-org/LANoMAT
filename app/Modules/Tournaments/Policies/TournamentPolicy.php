<?php

namespace App\Modules\Tournaments\Policies;

use App\Models\User;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;

class TournamentPolicy
{
    public function enroll(User $user, Tournament $tournament): bool
    {
        return $tournament->status === TournamentStatus::Enrollment;
    }

    public function checkIn(User $user, TournamentEntry $entry): bool
    {
        return $user->isOrga()
            || $entry->user_id === $user->id
            || $entry->team?->owner_id === $user->id;
    }

    public function manage(User $user, Tournament $tournament): bool
    {
        return $user->isOrga();
    }
}
