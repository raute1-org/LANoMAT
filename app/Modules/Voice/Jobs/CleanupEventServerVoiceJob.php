<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Models\VoiceServerChannel;
use App\Modules\Voice\Support\OccupiedChannels;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes the voice channels of a stopped event-level game server on every
 * provider that has a stored id (also one that was deactivated since, like
 * {@see CleanupTournamentVoiceJob}), then forgets the ids. Every delete is
 * explicit: temporary channels are not garbage-collected by the servers.
 * A row whose provider is no longer a valid case is dropped without a call.
 * Naturally idempotent: with no rows left there is nothing to do. A channel with
 * someone in it is left alone and checked again later.
 */
class CleanupEventServerVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $serverLinkId,
    ) {}

    /** How long to wait before looking again while someone is still in a channel. */
    public const RECHECK_AFTER_MINUTES = 2;

    public function handle(VoiceProviders $providers): void
    {
        $rows = VoiceServerChannel::query()->where('server_link_id', $this->serverLinkId)->get();
        $waiting = false;

        foreach ($rows as $row) {
            $provider = VoiceProvider::tryFrom($row->provider);

            if ($provider !== null) {
                $client = $providers->for($provider);

                // Removing a room moves people out of it: wait for the last one to leave.
                if (OccupiedChannels::anyOccupied($client, [$row->channel_id])) {
                    $waiting = true;

                    continue;
                }

                $client->deleteChannel($row->channel_id);
            }

            $row->delete();
        }

        // A sync queue runs a delayed job at once and would loop while someone stays.
        if ($waiting && config('queue.default') !== 'sync') {
            self::dispatch($this->serverLinkId)->delay(now()->addMinutes(self::RECHECK_AFTER_MINUTES));
        }
    }
}
