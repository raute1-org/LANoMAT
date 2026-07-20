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
 * DEFERRED VERIFICATION POINT: the exact REST envelope — whether the command
 * name is a body field (as implemented here: `{'command': ..., 'args': ...}`)
 * or a path segment, and the precise response shape for each command — is
 * not publicly documented and must be confirmed against a running server's
 * `:8095/api-docs` (see roadmap M11 "Mode A" deferred items). Every command
 * is funneled through the single {@see self::command()} helper so that
 * real-infra tuning of the wire format only ever touches one place.
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
            'query' => $query,
            'limit' => $limit,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        return array_map(fn (array $row) => $this->toTrack($row), $rows);
    }

    public function syncQueue(array $orderedUris): void
    {
        if ($orderedUris === []) {
            return;
        }

        // Best-effort mapping: play the first URI (replacing the queue),
        // then append the rest and reorder them into place. Music
        // Assistant's queue-management primitives are enqueue/reorder, not
        // "replace with this exact ordered list" — see the class docblock.
        $first = $orderedUris[0];
        $rest = array_slice($orderedUris, 1);

        $this->command('player_queues/play_media', [
            'queue_id' => $this->playerId,
            'media' => $first,
        ]);

        foreach ($rest as $position => $uri) {
            $this->command('player_queues/play_media', [
                'queue_id' => $this->playerId,
                'media' => $uri,
                'option' => 'add',
            ]);

            $this->command('player_queues/move_item', [
                'queue_id' => $this->playerId,
                'queue_item_id' => $uri,
                'pos_shift' => 0,
                'pos_target' => $position + 1,
            ]);
        }
    }

    public function nowPlaying(): ?NowPlayingDto
    {
        $payload = $this->command('player_queues/items', [
            'queue_id' => $this->playerId,
        ]);

        /** @var array<string, mixed> $result */
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        /** @var array<string, mixed>|null $currentItem */
        $currentItem = $result['current_item'] ?? null;

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
            positionSeconds: (int) ($currentItem['elapsed_time'] ?? 0),
            isPlaying: ($result['state'] ?? null) === 'playing',
        );
    }

    public function skip(): void
    {
        $this->command('player_queues/queue_command', [
            'queue_id' => $this->playerId,
            'command' => 'next',
        ]);
    }

    public function pause(): void
    {
        $this->command('player_queues/queue_command', [
            'queue_id' => $this->playerId,
            'command' => 'pause',
        ]);
    }

    public function resume(): void
    {
        $this->command('player_queues/queue_command', [
            'queue_id' => $this->playerId,
            'command' => 'play',
        ]);
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
            imageUrl: isset($row['image']) ? (string) $row['image'] : null,
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
