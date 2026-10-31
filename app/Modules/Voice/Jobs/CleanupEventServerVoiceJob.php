<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Models\VoiceServerChannel;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes the voice channels of a stopped event-level game server on every
 * provider that has a stored id (also one that was deactivated since, like
 * {@see CleanupTournamentVoiceJob}), then forgets the ids. Every delete is
 * explicit: temporary channels are not garbage-collected by the servers.
 * A row whose provider is no longer a valid case is dropped without a call.
 * Naturally idempotent: with no rows left there is nothing to do.
 */
class CleanupEventServerVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $serverLinkId,
    ) {}

    public function handle(VoiceProviders $providers): void
    {
        $rows = VoiceServerChannel::query()->where('server_link_id', $this->serverLinkId)->get();

        foreach ($rows as $row) {
            $provider = VoiceProvider::tryFrom($row->provider);

            if ($provider !== null) {
                $providers->for($provider)->deleteChannel($row->channel_id);
            }

            $row->delete();
        }
    }
}
