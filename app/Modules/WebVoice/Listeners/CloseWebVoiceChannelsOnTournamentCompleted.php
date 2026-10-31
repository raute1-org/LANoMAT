<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Listeners;

use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\WebVoice\Jobs\CloseChannelsJob;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Contracts\Queue\ShouldQueue;

/** The tournament is over: close every room left from matches that never completed on their own. */
class CloseWebVoiceChannelsOnTournamentCompleted implements ShouldQueue
{
    public function handle(TournamentCompleted $event): void
    {
        $matchIds = GameMatch::query()->where('tournament_id', $event->tournament->id)->pluck('id');

        $ids = WebVoiceChannel::query()
            ->whereIn('game_match_id', $matchIds)
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
