<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Listeners;

use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\WebVoice\Jobs\CloseChannelsJob;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A match result is in: close that match's rooms (empty ones at once, occupied ones when vacated). */
class CloseWebVoiceChannelsOnMatchCompleted implements ShouldQueue
{
    public function handle(MatchCompleted $event): void
    {
        $ids = WebVoiceChannel::query()
            ->where('game_match_id', $event->match->id)
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
