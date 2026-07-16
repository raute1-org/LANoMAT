<?php

namespace App\Modules\Tournaments\Listeners;

use App\Modules\Discord\Listeners\CreateMatchChannelOnReady;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Notifications\MatchReadyBell;
use App\Modules\Tournaments\Support\EntryRoster;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Reacts to a match becoming playable by notifying both rosters' users with
 * {@see MatchReadyBell} — the bell now carries what previously lived only in
 * the Discord match channel (see {@see CreateMatchChannelOnReady}).
 * Bell is the source of truth (a `database` entry always lands); the Discord
 * DM mirrors only per that user's `match` category preference.
 *
 * Roster resolution goes through {@see EntryRoster}, a small, reusable
 * helper shared with any future feature that needs "the users behind this
 * match's two entries".
 */
class NotifyRosterOnMatchReady implements ShouldQueue
{
    public function handle(MatchReady $event): void
    {
        $match = $event->match;

        if ($match->entry1 === null || $match->entry2 === null) {
            return;
        }

        $users = EntryRoster::usersForMatch($match);

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new MatchReadyBell($match));
    }
}
