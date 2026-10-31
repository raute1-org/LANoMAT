<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Listeners;

use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\WebVoice\Jobs\ProvisionMatchChannelsJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A match became playable: queue the creation of its two team rooms. */
class ProvisionWebVoiceChannelsOnMatchReady implements ShouldQueue
{
    public function handle(MatchReady $event): void
    {
        ProvisionMatchChannelsJob::dispatch($event->match->id);
    }
}
