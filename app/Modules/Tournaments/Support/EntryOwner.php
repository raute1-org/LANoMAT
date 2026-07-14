<?php

namespace App\Modules\Tournaments\Support;

use App\Models\User;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\TournamentEntry;

/**
 * Resolves the {@see User} id that "is" a {@see TournamentEntry}
 * for identity checks — the entry's own user for a solo entry, or the
 * team's owner for a team entry. Shared between {@see SubmitMatchReport}
 * (who reported) and the confirm/dispute identity guard (who may act on
 * that report).
 */
class EntryOwner
{
    public static function userId(TournamentEntry $entry): int
    {
        if ($entry->user_id !== null) {
            return $entry->user_id;
        }

        $team = $entry->team;

        if ($team !== null) {
            return $team->owner_id;
        }

        throw TournamentException::reporterHasNoOwner();
    }
}
