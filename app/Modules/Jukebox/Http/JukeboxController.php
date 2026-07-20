<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Actions\AddToQueue;
use App\Modules\Jukebox\Actions\RemoveItem;
use App\Modules\Jukebox\Actions\SkipCurrent;
use App\Modules\Jukebox\Actions\SyncQueueToPlayer;
use App\Modules\Jukebox\Actions\ToggleSkipVote;
use App\Modules\Jukebox\Actions\ToggleVote;
use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Events\JukeboxUpdated;
use App\Modules\Jukebox\Exceptions\JukeboxException;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Support\JukeboxQueue;
use App\Modules\Jukebox\Support\SkipThreshold;
use App\Modules\Jukebox\Support\TrackDto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The participant jukebox board: read-only for everyone (like seating/
 * tournaments/schedule/…), queueing/voting/skipping/removing require auth
 * and are further gated by JukeboxPolicy inside each Task-4 action —
 * this controller never bypasses that by checking policy itself before
 * calling an action.
 *
 * Every mutation, on success, mirrors the vote-ordered queue into Music
 * Assistant ({@see SyncQueueToPlayer}) and fires {@see JukeboxUpdated} so
 * every viewer's board partial-reloads. JukeboxException (policy refusal)
 * and MusicUnavailable (the search endpoint) are always degraded to a calm
 * German flash / empty result — never a 500.
 */
class JukeboxController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request, Event $event, JukeboxQueue $queue): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $viewer = $request->user();
        $current = $queue->current($event);
        $upcoming = $queue->upcoming($event);

        return Inertia::render('Jukebox/Index', [
            'event' => ['id' => $event->id, 'name' => $event->name, 'slug' => $event->slug],
            'nowPlaying' => $current !== null ? $this->nowPlayingDto($current) : null,
            'queue' => $upcoming->map(fn (JukeboxItem $item): array => $this->queueItemDto($item, $viewer?->id))->values()->all(),
            'skipThreshold' => SkipThreshold::for($event),
            'skipVotes' => $current?->skipVotes()->count() ?? 0,
            'canParticipate' => $viewer !== null && Gate::forUser($viewer)->allows('jukebox.participate', $event),
            'canModerate' => $viewer !== null && Gate::forUser($viewer)->allows('jukebox.moderate', $event),
            'labels' => trans('jukebox.page'),
        ]);
    }

    public function search(Request $request, Event $event, MusicClient $musicClient): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
        ]);

        try {
            $results = $musicClient->search($data['q']);
        } catch (MusicUnavailable $e) {
            return response()->json([
                'results' => [],
                'error' => trans($e->translationKey),
            ]);
        }

        return response()->json(array_map(fn (TrackDto $track): array => [
            'uri' => $track->uri,
            'title' => $track->title,
            'artist' => $track->artist,
            'durationSeconds' => $track->durationSeconds,
            'imageUrl' => $track->imageUrl,
        ], $results));
    }

    public function add(Request $request, Event $event, AddToQueue $action, SyncQueueToPlayer $sync): RedirectResponse
    {
        $data = $request->validate([
            'uri' => ['required', 'string', 'max:500'],
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:2000'],
        ]);

        $track = new TrackDto(
            uri: $data['uri'],
            title: $data['title'],
            artist: $data['artist'] ?? null,
            durationSeconds: $data['duration_seconds'] ?? null,
            imageUrl: $data['image_url'] ?? null,
        );

        try {
            $action->handle($this->authUser($request), $event, $track);
        } catch (JukeboxException $e) {
            return $this->flashError($e);
        }

        $sync->handle($event);
        JukeboxUpdated::dispatch($event->id);

        return back();
    }

    public function vote(Request $request, JukeboxItem $jukeboxItem, ToggleVote $action, SyncQueueToPlayer $sync): RedirectResponse
    {
        $event = $jukeboxItem->event()->firstOrFail();

        try {
            $action->handle($this->authUser($request), $jukeboxItem);
        } catch (JukeboxException $e) {
            return $this->flashError($e);
        }

        $sync->handle($event);
        JukeboxUpdated::dispatch($event->id);

        return back();
    }

    public function skipVote(Request $request, JukeboxItem $jukeboxItem, ToggleSkipVote $action, SyncQueueToPlayer $sync): RedirectResponse
    {
        $event = $jukeboxItem->event()->firstOrFail();

        try {
            $action->handle($this->authUser($request), $jukeboxItem);
        } catch (JukeboxException $e) {
            return $this->flashError($e);
        }

        $sync->handle($event);
        JukeboxUpdated::dispatch($event->id);

        return back();
    }

    public function skip(Request $request, Event $event, SkipCurrent $action, SyncQueueToPlayer $sync): RedirectResponse
    {
        try {
            $action->handle($this->authUser($request), $event);
        } catch (JukeboxException $e) {
            return $this->flashError($e);
        }

        $sync->handle($event);
        JukeboxUpdated::dispatch($event->id);

        return back();
    }

    public function remove(Request $request, JukeboxItem $jukeboxItem, RemoveItem $action, SyncQueueToPlayer $sync): RedirectResponse
    {
        $event = $jukeboxItem->event()->firstOrFail();

        try {
            $action->handle($this->authUser($request), $jukeboxItem);
        } catch (JukeboxException $e) {
            return $this->flashError($e);
        }

        $sync->handle($event);
        JukeboxUpdated::dispatch($event->id);

        return back();
    }

    /**
     * `id` is required by the participant page so the community skip-vote
     * control can POST to `jukebox.skip-vote`, which is keyed by
     * `{jukeboxItem}` (the currently-playing item has no other public route
     * param to address it by).
     *
     * @return array{id: int, title: string, artist: string|null, imageUrl: string|null, durationSeconds: int|null}
     */
    private function nowPlayingDto(JukeboxItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'artist' => $item->artist,
            'imageUrl' => $item->image_url,
            'durationSeconds' => $item->duration_seconds,
        ];
    }

    /**
     * Reduces an item to display-only fields: no user id, email, or any
     * other private column beyond the public display name.
     *
     * @return array{id: int, title: string, artist: string|null, imageUrl: string|null, voteCount: int, hasVoted: bool, addedByName: string|null}
     */
    private function queueItemDto(JukeboxItem $item, ?int $viewerId): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'artist' => $item->artist,
            'imageUrl' => $item->image_url,
            'voteCount' => $item->votes->count(),
            'hasVoted' => $viewerId !== null && $item->votes->contains('user_id', $viewerId),
            'addedByName' => $item->addedBy?->name,
        ];
    }

    private function flashError(JukeboxException|MusicUnavailable $e): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

        return back();
    }
}
