<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voice\Contracts\MumbleClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Provisions the Mumble channel tree for a tournament once it goes live
 * ({@see TournamentStarted}): a root channel
 * named `🏆 <Tournament>` and, for team tournaments (`team_size > 1`), one
 * child channel per non-withdrawn entry — so rosters have a persistent home
 * channel for the whole event, independent of any single match.
 *
 * The created channel ids are persisted onto `tournaments.settings['voice']`
 * so {@see CleanupTournamentVoiceJob} can tear the tree down later. Skips
 * entirely if that key is already populated, so a re-fired `TournamentStarted`
 * never creates a duplicate tree.
 */
class ProvisionTournamentVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tournamentId,
    ) {}

    public function handle(MumbleClient $client): void
    {
        $tournament = Tournament::query()->with('entries')->find($this->tournamentId);

        if ($tournament === null) {
            return;
        }

        $settings = $tournament->settings ?? [];

        if (isset($settings['voice']['tournament_channel_id'])) {
            return;
        }

        $tournamentChannel = $client->createChannel("🏆 {$tournament->name}");

        $teamChannelIds = [];

        if ($tournament->team_size > 1) {
            $entries = $tournament->entries
                ->reject(fn ($entry) => $entry->status === EntryStatus::Withdrawn);

            foreach ($entries as $entry) {
                $teamChannel = $client->createChannel($entry->display_name, $tournamentChannel->id);
                $teamChannelIds[] = $teamChannel->id;
            }
        }

        $settings['voice'] = [
            'tournament_channel_id' => $tournamentChannel->id,
            'team_channel_ids' => $teamChannelIds,
        ];

        $tournament->update(['settings' => $settings]);
    }
}
