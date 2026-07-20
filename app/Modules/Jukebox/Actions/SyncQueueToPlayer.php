<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Console\JukeboxTickCommand;
use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use App\Modules\Jukebox\Support\JukeboxQueue;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors LANoMAT's vote-ordered upcoming queue into Music Assistant so the
 * player's own queue always matches what participants see and voted for.
 *
 * Called after every queue/vote mutation (queueing, voting, removing — see
 * the participant controller) and from {@see JukeboxTickCommand}
 * after promoting the next item.
 *
 * Music Assistant being unreachable must never fail a queue/vote mutation:
 * {@see MusicUnavailable} is caught here, logged, and swallowed — the
 * jukebox simply pauses syncing until MA is reachable again.
 */
class SyncQueueToPlayer
{
    public function __construct(
        private readonly MusicClient $musicClient,
        private readonly JukeboxQueue $queue,
    ) {}

    public function handle(Event $event): void
    {
        $uris = $this->queue->upcoming($event)->pluck('uri')->all();

        try {
            $this->musicClient->syncQueue($uris);
        } catch (MusicUnavailable $e) {
            Log::warning('Jukebox: failed to sync queue to Music Assistant, jukebox sync paused.', [
                'event_id' => $event->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
