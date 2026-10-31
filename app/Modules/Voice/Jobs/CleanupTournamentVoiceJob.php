<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\Support\OccupiedChannels;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tears down all voice channels belonging to a finished tournament
 * ({@see TournamentCompleted}) on **every provider** that has stored
 * channel ids ({@see VoiceProviders}) — not just the currently active set:
 * the root channel, every team channel provisioned by
 * {@see ProvisionTournamentVoiceJob}, any leftover per-match channels from
 * {@see ProvisionMatchVoiceJob}, and the per-server shared channel from
 * {@see ProvisionServerVoiceJob} (issue #13), for matches whose cleanup
 * never reached them (e.g. matches that never completed on their own). A
 * provider key that was
 * deactivated after provisioning is still resolved via
 * {@see VoiceProviders::for()} so its leftover channels get cleaned up; a
 * stored key that is no longer a valid {@see VoiceProvider} case is skipped
 * gracefully rather than crashing the job.
 *
 * Every delete is explicit — `createChannel(..., temporary: true)` is a
 * documented server-side no-op (see {@see HttpMumbleClient}),
 * so nothing here may rely on Murmur's temporary-channel garbage collection.
 * Clears the stored ids afterwards so this job is naturally idempotent.
 *
 * Empty rooms are deleted at once. A room with someone in it, or in one of its
 * sub-channels, is left alone so nobody is moved out of a conversation, and the job
 * checks again every {@see self::RECHECK_AFTER_MINUTES} minutes until the last person
 * has left. Providers are handled independently.
 */
class CleanupTournamentVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tournamentId,
    ) {}

    /** How long to wait before looking again while someone is still in a channel. */
    public const RECHECK_AFTER_MINUTES = 2;

    public function handle(VoiceProviders $providers): void
    {
        $tournament = Tournament::query()->find($this->tournamentId);

        if ($tournament === null) {
            return;
        }

        $settings = $tournament->settings ?? [];
        $matches = GameMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotNull('voice_channels')
            ->get();

        // Channel ids per provider, in the order they are deleted: teams, then the
        // tournament channel, then every per-match channel.
        $idsByProvider = [];

        foreach ($settings['voice'] ?? [] as $value => $subtree) {
            if (VoiceProvider::tryFrom((string) $value) === null) {
                continue;
            }

            foreach ($subtree['team_channel_ids'] ?? [] as $teamChannelId) {
                $idsByProvider[$value][] = $teamChannelId;
            }

            if (isset($subtree['tournament_channel_id'])) {
                $idsByProvider[$value][] = $subtree['tournament_channel_id'];
            }
        }

        foreach ($matches as $match) {
            foreach ($match->voice_channels ?? [] as $value => $subtree) {
                if (VoiceProvider::tryFrom((string) $value) === null) {
                    continue;
                }

                foreach (['entry1_channel_id', 'entry2_channel_id', 'server_channel_id'] as $key) {
                    if (($subtree[$key] ?? null) !== null) {
                        $idsByProvider[$value][] = $subtree[$key];
                    }
                }
            }
        }

        // Empty rooms go at once. A room with someone in it, or above someone, stays and keeps its
        // stored id: removing it would move people out mid-conversation, so this job looks again
        // later. Providers are handled independently.
        $deleted = [];
        $waiting = false;

        foreach ($idsByProvider as $value => $ids) {
            $ids = array_values(array_unique($ids));
            $client = $providers->for(VoiceProvider::from((string) $value));
            $retained = OccupiedChannels::retained($client, $ids);

            foreach ($ids as $channelId) {
                if (in_array($channelId, $retained, true)) {
                    $waiting = true;

                    continue;
                }

                $client->deleteChannel($channelId);
                $deleted[$value][$channelId] = true;
            }
        }

        // Forget what was deleted, and keys of a provider that no longer exists.
        $remaining = [];

        foreach ($settings['voice'] ?? [] as $value => $subtree) {
            if (VoiceProvider::tryFrom((string) $value) === null) {
                continue;
            }

            $gone = $deleted[$value] ?? [];
            $teams = array_values(array_filter(
                $subtree['team_channel_ids'] ?? [],
                fn ($id): bool => ! isset($gone[$id]),
            ));
            $root = $subtree['tournament_channel_id'] ?? null;
            $root = $root !== null && ! isset($gone[$root]) ? $root : null;

            if ($teams === [] && $root === null) {
                continue;
            }

            $remaining[$value] = ['team_channel_ids' => $teams] + ($root !== null ? ['tournament_channel_id' => $root] : []);
        }

        if ($remaining === []) {
            unset($settings['voice']);
        } else {
            $settings['voice'] = $remaining;
        }

        if (($tournament->settings ?? []) !== $settings) {
            $tournament->update(['settings' => $settings]);
        }

        foreach ($matches as $match) {
            $kept = [];

            foreach ($match->voice_channels ?? [] as $value => $subtree) {
                if (VoiceProvider::tryFrom((string) $value) === null) {
                    continue;
                }

                $gone = $deleted[$value] ?? [];
                $channels = array_filter(
                    $subtree,
                    fn ($id, $key): bool => in_array($key, ['entry1_channel_id', 'entry2_channel_id', 'server_channel_id'], true)
                        && $id !== null
                        && ! isset($gone[$id]),
                    ARRAY_FILTER_USE_BOTH,
                );

                if ($channels !== []) {
                    $kept[$value] = $channels;
                }
            }

            $match->update(['voice_channels' => $kept === [] ? null : $kept]);
        }

        if ($waiting) {
            self::recheckLater($this->tournamentId);
        }
    }

    /**
     * A sync queue runs a delayed job at once, which would loop for as long as
     * someone stays in a channel, so nothing is queued there.
     */
    private static function recheckLater(int $tournamentId): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        self::dispatch($tournamentId)->delay(now()->addMinutes(self::RECHECK_AFTER_MINUTES));
    }
}
