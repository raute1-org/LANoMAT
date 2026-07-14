<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voice\Contracts\MumbleClient;
use App\Modules\Voice\HttpMumbleClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tears down all Mumble channels belonging to a finished tournament
 * ({@see TournamentCompleted}): the root
 * channel, every team channel provisioned by
 * {@see ProvisionTournamentVoiceJob}, and any leftover per-match channels
 * from {@see ProvisionMatchVoiceJob} that a match's cleanup never reached
 * (e.g. matches that never completed on their own).
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

    public function handle(MumbleClient $client): void
    {
        $tournament = Tournament::query()->find($this->tournamentId);

        if ($tournament === null) {
            return;
        }

        $settings = $tournament->settings ?? [];
        $voice = $settings['voice'] ?? null;

        if ($voice !== null) {
            foreach ($voice['team_channel_ids'] ?? [] as $teamChannelId) {
                $client->deleteChannel($teamChannelId);
            }

            if (isset($voice['tournament_channel_id'])) {
                $client->deleteChannel($voice['tournament_channel_id']);
            }

            unset($settings['voice']);
            $tournament->update(['settings' => $settings]);
        }

        $matches = GameMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotNull('voice_channels')
            ->get();

        foreach ($matches as $match) {
            $entry1ChannelId = $match->voice_channels['entry1_channel_id'] ?? null;
            $entry2ChannelId = $match->voice_channels['entry2_channel_id'] ?? null;

            if ($entry1ChannelId !== null) {
                $client->deleteChannel($entry1ChannelId);
            }

            if ($entry2ChannelId !== null) {
                $client->deleteChannel($entry2ChannelId);
            }

            $match->update(['voice_channels' => null]);
        }
    }
}
