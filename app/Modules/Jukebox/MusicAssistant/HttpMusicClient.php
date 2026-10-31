<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\MusicAssistant;

use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Contracts\PlaybackControl;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use App\Modules\Jukebox\Support\NowPlayingDto;
use App\Modules\Jukebox\Support\TrackDto;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to a Music Assistant server over its HTTP/JSON-RPC surface
 * (`{base_url}/api`, default port 8095), authenticated with a long-lived
 * bearer token. Music Assistant is primarily a WebSocket API; the HTTP
 * surface exposes the same namespaced `@api_command`s (`music/search`,
 * `player_queues/play_media`, `player_queues/items`,
 * `player_queues/move_item`, `player_queues/queue_command`) used by its
 * WS clients.
 *
 * VERIFIED against Music Assistant 2.10.9 (2026-10-31): the envelope is
 * `POST {base}/api` with `{'command': ..., 'args': ...}`; the command and
 * argument names come from the server's own `/api-docs/commands.json`
 * (`music/search` takes `search_query` and answers with results grouped by
 * media type; the queue commands are `player_queues/get_active_queue`,
 * `play_media`, `next`, `pause`, `resume`, `delete_item`). Every command goes
 * through {@see self::command()}, so a future wire change touches one place.
 * A web player's id changes with every browser connection; configure a
 * stable player (Snapcast, Chromecast, ...) for a real event.
 */
class HttpMusicClient implements MusicClient, PlaybackControl
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $playerId,
    ) {}

    public function search(string $query, int $limit = 20): array
    {
        $payload = $this->command('music/search', [
            'search_query' => $query,
            'media_types' => ['track'],
            'limit' => $limit,
        ]);

        // Music Assistant groups results by media type; only tracks are playable
        // jukebox entries.
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($payload['tracks'] ?? null) ? $payload['tracks'] : [];

        return array_map(fn (array $row) => $this->toTrack($row), $rows);
    }

    public function syncQueue(array $orderedUris): void
    {
        if ($orderedUris === []) {
            return;
        }

        $queue = $this->activeQueue();
        $playingUri = $this->currentUri($queue);

        if ($playingUri !== null && $playingUri === $orderedUris[0]) {
            // The first entry is the track that is playing right now: leave it
            // alone (replacing the queue would restart it) and make only the
            // upcoming part match.
            $this->replaceUpcoming(array_slice($orderedUris, 1), $queue);

            return;
        }

        // Nothing (or something else) is playing: start from this list.
        $this->command('player_queues/play_media', [
            'queue_id' => $this->playerId,
            'media' => $orderedUris,
            'option' => 'replace',
        ]);
    }

    public function nowPlaying(): ?NowPlayingDto
    {
        $queue = $this->activeQueue();

        if (! $this->holdsTrack($queue)) {
            return null;
        }

        /** @var array<string, mixed>|null $currentItem */
        $currentItem = is_array($queue['current_item'] ?? null) ? $queue['current_item'] : null;

        if ($currentItem === null) {
            return null;
        }

        /** @var array<string, mixed> $mediaItem */
        $mediaItem = is_array($currentItem['media_item'] ?? null) ? $currentItem['media_item'] : [];

        return new NowPlayingDto(
            uri: (string) ($mediaItem['uri'] ?? ''),
            title: (string) ($mediaItem['name'] ?? ''),
            artist: $this->firstArtistName($mediaItem),
            durationSeconds: isset($mediaItem['duration']) ? (int) $mediaItem['duration'] : null,
            positionSeconds: (int) floor((float) ($queue['elapsed_time'] ?? 0)),
            isPlaying: ($queue['state'] ?? null) === 'playing',
        );
    }

    public function skip(): void
    {
        $this->command('player_queues/next', ['queue_id' => $this->playerId]);
    }

    public function pause(): void
    {
        $this->command('player_queues/pause', ['queue_id' => $this->playerId]);
    }

    public function resume(): void
    {
        $this->command('player_queues/resume', ['queue_id' => $this->playerId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activeQueue(): array
    {
        return $this->command('player_queues/get_active_queue', ['player_id' => $this->playerId]);
    }

    /**
     * Whether the queue is currently holding a track (playing or paused), as
     * opposed to having finished or never started.
     *
     * Music Assistant reports a PAUSED web/Sendspin player as `idle` (checked
     * against a real server), exactly like a queue whose last track ended. The
     * two differ in `elapsed_time`: a pause keeps the position, a finished
     * queue resets it to 0. Treating every `idle` queue as "nothing playing"
     * would make the jukebox tick think a paused track had ended and skip it.
     *
     * @param  array<string, mixed>  $queue
     */
    private function holdsTrack(array $queue): bool
    {
        if (! is_array($queue['current_item'] ?? null)) {
            return false;
        }

        $state = $queue['state'] ?? 'idle';

        if (in_array($state, ['playing', 'paused'], true)) {
            return true;
        }

        return $state === 'idle' && (float) ($queue['elapsed_time'] ?? 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    private function currentUri(array $queue): ?string
    {
        if (! $this->holdsTrack($queue)) {
            return null;
        }

        $item = is_array($queue['current_item'] ?? null) ? $queue['current_item'] : [];
        $media = is_array($item['media_item'] ?? null) ? $item['media_item'] : [];
        $uri = $media['uri'] ?? null;

        return is_string($uri) && $uri !== '' ? $uri : null;
    }

    /**
     * Replace everything after the current item with $uris, in order.
     *
     * @param  array<int, string>  $uris
     * @param  array<string, mixed>  $queue
     */
    private function replaceUpcoming(array $uris, array $queue): void
    {
        if ($uris !== []) {
            $this->command('player_queues/play_media', [
                'queue_id' => $this->playerId,
                'media' => $uris,
                'option' => 'replace_next',
            ]);

            return;
        }

        // `replace_next` with nothing cannot clear the tail, so delete the
        // upcoming items one by one (highest index first keeps indexes valid).
        $currentIndex = (int) ($queue['current_index'] ?? 0);
        $items = $this->command('player_queues/items', ['queue_id' => $this->playerId]);

        $upcoming = array_values(array_filter(
            $items,
            fn ($item) => is_array($item) && (int) ($item['sort_index'] ?? 0) > $currentIndex,
        ));

        foreach (array_reverse($upcoming) as $item) {
            $this->command('player_queues/delete_item', [
                'queue_id' => $this->playerId,
                'item_id_or_index' => (string) ($item['queue_item_id'] ?? ''),
            ]);
        }
    }

    /**
     * Sends one JSON-RPC-style command to Music Assistant's HTTP surface and
     * returns the decoded response body. This is the ONE place that knows
     * the wire envelope — see the class docblock's deferred-verification
     * note. Throws {@see MusicUnavailable} for every transport problem
     * (connection failure or a failed status); it is the only exception this
     * client ever throws, so callers can degrade gracefully instead of
     * catching raw HTTP/Guzzle exceptions.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function command(string $name, array $args = []): array
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->post("{$this->baseUrl}/api", [
                    'command' => $name,
                    'args' => $args,
                ]);
        } catch (Throwable) {
            throw MusicUnavailable::unreachable();
        }

        if ($response->failed()) {
            throw MusicUnavailable::requestFailed($response->status());
        }

        /** @var mixed $decoded */
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toTrack(array $row): TrackDto
    {
        return new TrackDto(
            uri: (string) ($row['uri'] ?? ''),
            title: (string) ($row['name'] ?? ''),
            artist: $this->firstArtistName($row),
            durationSeconds: isset($row['duration']) ? (int) $row['duration'] : null,
            imageUrl: is_string($row['image'] ?? null) ? $row['image'] : null,
        );
    }

    /**
     * Music Assistant reports a media item's `artists` as a list of artist
     * objects (each with a `name`); LANoMAT's {@see TrackDto}/
     * {@see NowPlayingDto} only need the first one.
     *
     * @param  array<string, mixed>  $mediaItem
     */
    private function firstArtistName(array $mediaItem): ?string
    {
        /** @var array<int, array<string, mixed>>|null $artists */
        $artists = is_array($mediaItem['artists'] ?? null) ? $mediaItem['artists'] : null;

        if ($artists === null || $artists === []) {
            return null;
        }

        $name = $artists[0]['name'] ?? null;

        return $name !== null ? (string) $name : null;
    }
}
