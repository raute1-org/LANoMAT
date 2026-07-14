<?php

declare(strict_types=1);

namespace App\Modules\Voice\Listeners;

use App\Modules\Discord\Listeners\CreateMatchChannelOnReady;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Voice\Jobs\ProvisionTournamentVoiceJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a tournament going live by queuing
 * {@see ProvisionTournamentVoiceJob} — kept as a thin dispatch wrapper so the
 * Mumble API calls never run inline with the bracket-generation transaction
 * that triggered this event (mirrors Task 18's
 * {@see CreateMatchChannelOnReady}).
 */
class ProvisionVoiceOnStart implements ShouldQueue
{
    public function handle(TournamentStarted $event): void
    {
        ProvisionTournamentVoiceJob::dispatch($event->tournament->id);
    }
}
