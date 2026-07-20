<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Console;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Actions\SyncQueueToPlayer;
use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Events\JukeboxUpdated;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use App\Modules\Jukebox\Support\JukeboxQueue;
use App\Modules\Jukebox\Support\NowPlayingDto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Polls Music Assistant's now-playing state for every event with an active
 * jukebox (status Live) and reconciles LANoMAT's queue against it: once MA
 * reports that the current `Playing` item has finished (a different uri is
 * now playing, or nothing is), the old item is marked `Played` and the top
 * `upcoming` item is promoted to `Playing`, synced back to MA, and announced
 * via {@see JukeboxUpdated}. If nothing is currently `Playing` but items are
 * queued, this also kicks off playback of the first one.
 *
 * Polling (not a push from Music Assistant) is a deliberate v1 choice — see
 * the M11 roadmap insights — so `everyMinute` is a pragmatic cadence: fast
 * enough that the queue view feels live, slow enough to avoid hammering MA.
 * A future revision could shorten this if the UX turns out to need it.
 */
class JukeboxTickCommand extends Command
{
    protected $signature = 'lanomat:jukebox-tick';

    protected $description = 'Poll Music Assistant now-playing state per event and reconcile the jukebox queue.';

    public function handle(MusicClient $musicClient, JukeboxQueue $queue, SyncQueueToPlayer $sync): int
    {
        Event::query()
            ->where('status', EventStatus::Live)
            ->each(function (Event $event) use ($musicClient, $queue, $sync): void {
                $this->tickEvent($event, $musicClient, $queue, $sync);
            });

        return self::SUCCESS;
    }

    private function tickEvent(Event $event, MusicClient $musicClient, JukeboxQueue $queue, SyncQueueToPlayer $sync): void
    {
        try {
            $nowPlaying = $musicClient->nowPlaying();
        } catch (MusicUnavailable $e) {
            Log::warning('Jukebox: failed to poll Music Assistant now-playing state, skipping tick for this event.', [
                'event_id' => $event->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $current = $queue->current($event);

        $currentFinished = $current !== null
            && (! $nowPlaying instanceof NowPlayingDto || $nowPlaying->uri !== $current->uri);

        if ($current !== null && ! $currentFinished) {
            // MA still reports the same track as LANoMAT's current item —
            // nothing to reconcile.
            return;
        }

        if ($current === null && $nowPlaying !== null) {
            // MA is playing something LANoMAT doesn't track as current (e.g.
            // manually queued outside LANoMAT); nothing for us to promote.
            return;
        }

        $next = $queue->upcoming($event)->first();

        if ($next === null) {
            // Nothing to promote — mark the finished item played (if any)
            // and stop; the jukebox is simply empty for now.
            if ($currentFinished) {
                $current->forceFill(['status' => QueueItemStatus::Played, 'played_at' => now()])->save();
                JukeboxUpdated::dispatch($event->id);
            }

            return;
        }

        if ($currentFinished) {
            $current->forceFill(['status' => QueueItemStatus::Played, 'played_at' => now()])->save();
        }

        $next->forceFill(['status' => QueueItemStatus::Playing])->save();

        $sync->handle($event);

        JukeboxUpdated::dispatch($event->id);
    }
}
