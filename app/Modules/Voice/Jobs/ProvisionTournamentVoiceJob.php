<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Provisions the voice channel tree for a tournament once it goes live
 * ({@see TournamentStarted}) on **every active provider**
 * ({@see VoiceProviders}): a root channel named `🏆 <Tournament>` and, for
 * team tournaments (`team_size > 1`), one child channel per non-withdrawn
 * entry — so rosters have a persistent home channel for the whole event,
 * independent of any single match, mirrored identically across backends so a
 * team can switch providers instantly.
 *
 * The created channel ids are persisted onto
 * `tournaments.settings['voice'][<provider>]` so
 * {@see CleanupTournamentVoiceJob} can tear each provider's tree down later.
 * Idempotency is per provider: a provider whose subtree already exists is
 * skipped, but a provider newly added to the active set is still provisioned
 * on a re-fired `TournamentStarted`.
 */
class ProvisionTournamentVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tournamentId,
    ) {}

    public function handle(VoiceProviders $providers): void
    {
        $tournament = Tournament::query()->with('entries')->find($this->tournamentId);

        if ($tournament === null) {
            return;
        }

        $settings = $tournament->settings ?? [];
        $voice = $settings['voice'] ?? [];

        $entries = $tournament->team_size > 1
            ? $tournament->entries->reject(fn ($entry) => $entry->status === EntryStatus::Withdrawn)
            : collect();

        foreach ($providers->active() as $value => $client) {
            if (isset($voice[$value]['tournament_channel_id'])) {
                continue;
            }

            $created = [];

            try {
                $tournamentChannel = $client->createChannel("🏆 {$tournament->name}");
                $created[] = $tournamentChannel->id;

                $teamChannelIds = [];

                foreach ($entries as $entry) {
                    $teamChannel = $client->createChannel($entry->display_name, $tournamentChannel->id);
                    $created[] = $teamChannel->id;
                    $teamChannelIds[] = $teamChannel->id;
                }
            } catch (Throwable $e) {
                // Take back the half-built tree of this provider, so a retry starts clean.
                foreach (array_reverse($created) as $channelId) {
                    try {
                        $client->deleteChannel($channelId);
                    } catch (Throwable $deleteFailure) {
                        report($deleteFailure);
                    }
                }

                throw $e;
            }

            $voice[$value] = [
                'tournament_channel_id' => $tournamentChannel->id,
                'team_channel_ids' => $teamChannelIds,
            ];

            // Stored as soon as this provider is done: if a later provider fails, a retry
            // must not build this tree a second time (it would be left behind).
            $settings['voice'] = $voice;
            $tournament->update(['settings' => $settings]);
        }
    }
}
