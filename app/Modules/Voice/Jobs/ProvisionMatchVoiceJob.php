<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates temporary per-match team voice channels once a match becomes
 * playable ({@see MatchReady}) on **every active provider**
 * ({@see VoiceProviders}) — one channel per roster, named after the entry's
 * display name, mirrored identically across backends. `temporary: true` is
 * passed for documentation/forward-compatibility, but per
 * {@see HttpMumbleClient::createChannel()} this currently
 * has no server-side effect, so {@see CleanupTournamentVoiceJob} MUST delete
 * these channels explicitly rather than relying on Murmur's temp-channel GC.
 *
 * The created channel ids are persisted onto
 * `matches.voice_channels[<provider>]`. Idempotency is per provider: a
 * provider whose subtree already exists is skipped, but a provider newly
 * added to the active set is still provisioned on a re-fired `MatchReady`.
 */
class ProvisionMatchVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $matchId,
    ) {}

    public function handle(VoiceProviders $providers): void
    {
        $match = GameMatch::query()->with(['entry1', 'entry2'])->find($this->matchId);

        if ($match === null || $match->entry1 === null || $match->entry2 === null) {
            return;
        }

        $voiceChannels = $match->voice_channels ?? [];

        foreach ($providers->active() as $value => $client) {
            if (isset($voiceChannels[$value])) {
                continue;
            }

            $created = [];

            try {
                $entry1Channel = $client->createChannel($match->entry1->display_name, null, true);
                $created[] = $entry1Channel->id;
                $entry2Channel = $client->createChannel($match->entry2->display_name, null, true);
                $created[] = $entry2Channel->id;
            } catch (Throwable $e) {
                // Take back what this provider already made, so a retry starts clean.
                foreach ($created as $channelId) {
                    try {
                        $client->deleteChannel($channelId);
                    } catch (Throwable $deleteFailure) {
                        report($deleteFailure);
                    }
                }

                throw $e;
            }

            $voiceChannels[$value] = [
                'entry1_channel_id' => $entry1Channel->id,
                'entry2_channel_id' => $entry2Channel->id,
            ];

            // Stored as soon as this provider is done: if a later provider fails, a retry
            // must not create these rooms a second time (they would be left behind).
            $match->update(['voice_channels' => $voiceChannels]);
        }
    }
}
