<?php

declare(strict_types=1);

namespace App\Modules\Voice\Listeners;

use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Voice\Jobs\ProvisionMatchVoiceJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Reacts to a match becoming playable by queuing
 * {@see ProvisionMatchVoiceJob} — kept as a thin dispatch wrapper, same
 * rationale as {@see ProvisionVoiceOnStart}.
 */
class ProvisionMatchVoiceOnReady implements ShouldQueue
{
    public function handle(MatchReady $event): void
    {
        ProvisionMatchVoiceJob::dispatch($event->match->id);
    }
}
