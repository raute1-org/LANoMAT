<?php

declare(strict_types=1);

namespace App\Modules\Presence\Console;

use App\Modules\Presence\Support\LocationSnapshotBuilder;
use Illuminate\Console\Command;

/**
 * Refreshes the cached "who is where" snapshot (scheduled every 30 seconds while an event is
 * live). All voice, LiveKit and Steam calls happen here, none inside a request.
 */
class RefreshLocationsCommand extends Command
{
    protected $signature = 'presence:refresh-locations';

    protected $description = 'Refresh the cached voice and game locations of the participants.';

    public function handle(LocationSnapshotBuilder $builder): int
    {
        $snapshot = $builder->refresh();

        $this->info(count($snapshot->people).' participant(s) located.');

        return self::SUCCESS;
    }
}
