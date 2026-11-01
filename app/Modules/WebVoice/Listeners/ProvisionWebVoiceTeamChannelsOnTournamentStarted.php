<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Listeners;

use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\WebVoice\Jobs\ProvisionTeamChannelsJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A team tournament went live: queue every team's standing room (mirrors the Mumble/TeamSpeak tree). */
class ProvisionWebVoiceTeamChannelsOnTournamentStarted implements ShouldQueue
{
    public function handle(TournamentStarted $event): void
    {
        ProvisionTeamChannelsJob::dispatch($event->tournament->id);
    }
}
