<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Jobs;

use App\Modules\WebVoice\Actions\CloseChannels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Archives finished channels that are empty and looks again later for the ones still in use,
 * the same rule as the Voice module's tournament cleanup: empty rooms go at once,
 * occupied rooms wait until the last person has left.
 */
class CloseChannelsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $channelIds
     */
    public function __construct(public readonly array $channelIds) {}

    public function handle(CloseChannels $close): void
    {
        $waiting = $close->handle($this->channelIds);

        // A sync queue runs a delayed job at once, which would loop for as long as someone stays in a room.
        if ($waiting !== [] && config('queue.default') !== 'sync') {
            self::dispatch($waiting)->delay(now()->addMinutes((int) config('webvoice.lifecycle.recheck_minutes')));
        }
    }
}
