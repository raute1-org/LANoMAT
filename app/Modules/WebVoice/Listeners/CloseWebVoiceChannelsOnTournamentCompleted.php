<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Listeners;

use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Jobs\CloseChannelsJob;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Contracts\Queue\ShouldQueue;

/** The tournament is over: close every room left from unfinished matches and every team's standing room. */
class CloseWebVoiceChannelsOnTournamentCompleted implements ShouldQueue
{
    public function handle(TournamentCompleted $event): void
    {
        $matchIds = GameMatch::query()->where('tournament_id', $event->tournament->id)->pluck('id');

        $entryIds = TournamentEntry::query()->where('tournament_id', $event->tournament->id)->pluck('id');

        // Rooms of unfinished matches, and the teams' standing rooms of the whole tournament.
        $ids = WebVoiceChannel::query()
            ->where(fn ($rooms) => $rooms
                ->whereIn('game_match_id', $matchIds)
                ->orWhere(fn ($teams) => $teams->where('kind', ChannelKind::Team->value)->whereIn('tournament_entry_id', $entryIds)))
            ->whereNull('archived_at')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($ids !== []) {
            CloseChannelsJob::dispatch($ids);
        }
    }
}
