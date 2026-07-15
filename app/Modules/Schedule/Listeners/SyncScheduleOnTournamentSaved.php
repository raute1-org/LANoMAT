<?php

namespace App\Modules\Schedule\Listeners;

use App\Modules\Schedule\Actions\SyncTournamentScheduleItem;
use App\Modules\Tournaments\Events\TournamentSaved;

class SyncScheduleOnTournamentSaved
{
    public function __construct(
        private readonly SyncTournamentScheduleItem $syncTournamentScheduleItem,
    ) {}

    public function handle(TournamentSaved $event): void
    {
        $this->syncTournamentScheduleItem->handle($event->tournament);
    }
}
