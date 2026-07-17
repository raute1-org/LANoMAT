<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tears down all voice channels belonging to a finished tournament
 * ({@see TournamentCompleted}) on **every provider** that has stored
 * channel ids ({@see VoiceProviders}) — not just the currently active set:
 * the root channel, every team channel provisioned by
 * {@see ProvisionTournamentVoiceJob}, and any leftover per-match channels
 * from {@see ProvisionMatchVoiceJob} that a match's cleanup never reached
 * (e.g. matches that never completed on their own). A provider key that was
 * deactivated after provisioning is still resolved via
 * {@see VoiceProviders::for()} so its leftover channels get cleaned up; a
 * stored key that is no longer a valid {@see VoiceProvider} case is skipped
 * gracefully rather than crashing the job.
 *
 * Every delete is explicit — `createChannel(..., temporary: true)` is a
 * documented server-side no-op (see {@see HttpMumbleClient}),
 * so nothing here may rely on Murmur's temporary-channel garbage collection.
 * Clears the stored ids afterwards so this job is naturally idempotent.
 */
class CleanupTournamentVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tournamentId,
    ) {}

    public function handle(VoiceProviders $providers): void
    {
        $tournament = Tournament::query()->find($this->tournamentId);

        if ($tournament === null) {
            return;
        }

        $settings = $tournament->settings ?? [];
        $voice = $settings['voice'] ?? null;

        if ($voice !== null) {
            foreach ($voice as $value => $subtree) {
                $provider = VoiceProvider::tryFrom($value);

                if ($provider === null) {
                    continue;
                }

                $client = $providers->for($provider);

                foreach ($subtree['team_channel_ids'] ?? [] as $teamChannelId) {
                    $client->deleteChannel($teamChannelId);
                }

                if (isset($subtree['tournament_channel_id'])) {
                    $client->deleteChannel($subtree['tournament_channel_id']);
                }
            }

            unset($settings['voice']);
            $tournament->update(['settings' => $settings]);
        }

        $matches = GameMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotNull('voice_channels')
            ->get();

        foreach ($matches as $match) {
            foreach ($match->voice_channels ?? [] as $value => $subtree) {
                $provider = VoiceProvider::tryFrom($value);

                if ($provider === null) {
                    continue;
                }

                $client = $providers->for($provider);

                $entry1ChannelId = $subtree['entry1_channel_id'] ?? null;
                $entry2ChannelId = $subtree['entry2_channel_id'] ?? null;

                if ($entry1ChannelId !== null) {
                    $client->deleteChannel($entry1ChannelId);
                }

                if ($entry2ChannelId !== null) {
                    $client->deleteChannel($entry2ChannelId);
                }
            }

            $match->update(['voice_channels' => null]);
        }
    }
}
