<?php

declare(strict_types=1);

namespace App\Modules\Voice\Listeners;

use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Voice\Jobs\CleanupTournamentVoiceJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a tournament finishing by queuing
 * {@see CleanupTournamentVoiceJob} to explicitly tear down every Mumble
 * channel it provisioned — see that job's docblock for why explicit deletion
 * (rather than relying on Murmur's temporary-channel GC) is required.
 */
class CleanupVoiceOnCompleted implements ShouldQueue
{
    public function handle(TournamentCompleted $event): void
    {
        CleanupTournamentVoiceJob::dispatch($event->tournament->id);
    }
}
