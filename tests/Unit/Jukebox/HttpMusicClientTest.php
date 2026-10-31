<?php

declare(strict_types=1);

use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Contracts\PlaybackControl;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Response shapes below were captured from a real Music Assistant 2.10.9
// (`POST /api` with {command, args}); keep them in sync with that server.

function maConfig(): void
{
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
}

/** @param  array<string, mixed>  $overrides */
function maQueue(array $overrides = []): array
{
    return array_merge([
        'queue_id' => 'hall',
        'state' => 'playing',
        'current_index' => 0,
        'elapsed_time' => 29.4475,
        'items' => 3,
        'current_item' => [
            'queue_item_id' => 'qi-0',
            'sort_index' => 0,
            'name' => 'LANoMAT QA - QA Testton 1',
            'duration' => 40,
            'media_item' => [
                'uri' => 'library://track/5',
                'name' => 'QA Testton 1',
                'duration' => 40,
                'media_type' => 'track',
                'artists' => [['name' => 'LANoMAT QA']],
                'image' => null,
            ],
        ],
    ], $overrides);
}

/** @return array<int, string> the commands sent so far, in order */
function sentCommands(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]['command'])->values()->all();
}

it('searches tracks with search_query and reads the tracks group', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response([
        'artists' => [], 'albums' => [], 'genres' => [],
        'tracks' => [[
            'uri' => 'library://track/5', 'name' => 'QA Testton 1', 'duration' => 40, 'media_type' => 'track',
            'artists' => [['name' => 'LANoMAT QA']], 'image' => ['type' => 'thumb', 'path' => '/x.jpg'],
        ]],
        'playlists' => [], 'radio' => [], 'sound_effects' => [],
    ])]);

    $tracks = app(MusicClient::class)->search('QA Testton', 5);

    expect($tracks)->toHaveCount(1)
        ->and($tracks[0]->uri)->toBe('library://track/5')
        ->and($tracks[0]->title)->toBe('QA Testton 1')
        ->and($tracks[0]->artist)->toBe('LANoMAT QA')
        ->and($tracks[0]->durationSeconds)->toBe(40)
        ->and($tracks[0]->imageUrl)->toBeNull();

    Http::assertSent(fn (Request $r) => $r->url() === 'http://ma:8095/api'
        && $r->hasHeader('Authorization', 'Bearer t')
        && $r['command'] === 'music/search'
        && $r['args']['search_query'] === 'QA Testton'
        && $r['args']['media_types'] === ['track']
        && $r['args']['limit'] === 5);
});

it('returns no tracks when the search finds none', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(['tracks' => [], 'radio' => []])]);

    expect(app(MusicClient::class)->search('nothing'))->toBe([]);
});

it('tolerates a search result missing optional fields', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(['tracks' => [['uri' => 'library://track/2', 'name' => 'Minimal']]])]);

    $tracks = app(MusicClient::class)->search('song');

    expect($tracks[0]->uri)->toBe('library://track/2')
        ->and($tracks[0]->artist)->toBeNull()
        ->and($tracks[0]->durationSeconds)->toBeNull()
        ->and($tracks[0]->imageUrl)->toBeNull();
});

it('throws MusicUnavailable when Music Assistant is unreachable or errors', function () {
    maConfig();
    Http::fake(['ma:8095/*' => fn () => throw new ConnectionException('down')]);
    expect(fn () => app(MusicClient::class)->search('x'))->toThrow(MusicUnavailable::class);

    Http::fake(['ma:8095/*' => Http::response('Internal server error', 500)]);
    expect(fn () => app(MusicClient::class)->search('x'))->toThrow(MusicUnavailable::class);
});

it('reads nowPlaying from the active queue', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue())]);

    $nowPlaying = app(MusicClient::class)->nowPlaying();

    expect($nowPlaying->uri)->toBe('library://track/5')
        ->and($nowPlaying->title)->toBe('QA Testton 1')
        ->and($nowPlaying->artist)->toBe('LANoMAT QA')
        ->and($nowPlaying->durationSeconds)->toBe(40)
        ->and($nowPlaying->positionSeconds)->toBe(29)
        ->and($nowPlaying->isPlaying)->toBeTrue();

    Http::assertSent(fn (Request $r) => $r['command'] === 'player_queues/get_active_queue'
        && $r['args']['player_id'] === 'hall');
});

it('reports a paused track as playing=false but still as now playing', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue(['state' => 'paused']))]);

    $nowPlaying = app(MusicClient::class)->nowPlaying();

    expect($nowPlaying->uri)->toBe('library://track/5')->and($nowPlaying->isPlaying)->toBeFalse();
});

it('returns null from nowPlaying for a finished queue (idle, position reset to 0)', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue(['state' => 'idle', 'elapsed_time' => 0]))]);

    expect(app(MusicClient::class)->nowPlaying())->toBeNull();
});

it('treats an idle queue that kept its position as a paused track, not a finished one', function () {
    // Real Music Assistant: a paused web player reports state=idle but keeps elapsed_time.
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue(['state' => 'idle', 'elapsed_time' => 12.8]))]);

    $nowPlaying = app(MusicClient::class)->nowPlaying();

    expect($nowPlaying->uri)->toBe('library://track/5')
        ->and($nowPlaying->positionSeconds)->toBe(12)
        ->and($nowPlaying->isPlaying)->toBeFalse();
});

it('returns null from nowPlaying when the queue has no current item', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue(['current_item' => null]))]);

    expect(app(MusicClient::class)->nowPlaying())->toBeNull();
});

it('sends skip, pause and resume as the real queue commands', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(null)]);

    $client = app(MusicClient::class);
    expect($client)->toBeInstanceOf(PlaybackControl::class);
    $client->skip();
    $client->pause();
    $client->resume();

    expect(sentCommands())->toBe(['player_queues/next', 'player_queues/pause', 'player_queues/resume']);
    Http::assertSent(fn (Request $r) => $r['command'] === 'player_queues/next' && $r['args']['queue_id'] === 'hall');
});

it('starts the whole list with replace when nothing is playing', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue(['state' => 'idle', 'elapsed_time' => 0]))]);

    app(MusicClient::class)->syncQueue(['library://track/1', 'library://track/2']);

    expect(sentCommands())->toBe(['player_queues/get_active_queue', 'player_queues/play_media']);
    Http::assertSent(fn (Request $r) => $r['command'] === 'player_queues/play_media'
        && $r['args']['queue_id'] === 'hall'
        && $r['args']['media'] === ['library://track/1', 'library://track/2']
        && $r['args']['option'] === 'replace');
});

it('replaces only the upcoming part when the first uri is already playing', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue())]);

    app(MusicClient::class)->syncQueue(['library://track/5', 'library://track/3', 'library://track/4']);

    expect(sentCommands())->toBe(['player_queues/get_active_queue', 'player_queues/play_media']);
    Http::assertSent(fn (Request $r) => $r['command'] === 'player_queues/play_media'
        && $r['args']['media'] === ['library://track/3', 'library://track/4']
        && $r['args']['option'] === 'replace_next');
});

it('restarts from the list when a different track is playing', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue())]);

    app(MusicClient::class)->syncQueue(['library://track/9']);

    Http::assertSent(fn (Request $r) => $r['command'] === 'player_queues/play_media'
        && $r['args']['option'] === 'replace'
        && $r['args']['media'] === ['library://track/9']);
});

it('clears the upcoming items one by one when only the playing track remains', function () {
    maConfig();
    Http::fake(function (Request $r) {
        return match ($r['command']) {
            'player_queues/get_active_queue' => Http::response(maQueue()),
            'player_queues/items' => Http::response([
                ['queue_item_id' => 'qi-0', 'sort_index' => 0],
                ['queue_item_id' => 'qi-1', 'sort_index' => 1],
                ['queue_item_id' => 'qi-2', 'sort_index' => 2],
            ]),
            default => Http::response(null),
        };
    });

    app(MusicClient::class)->syncQueue(['library://track/5']);

    expect(sentCommands())->toBe([
        'player_queues/get_active_queue',
        'player_queues/items',
        'player_queues/delete_item',
        'player_queues/delete_item',
    ]);
    $deleted = Http::recorded()
        ->filter(fn (array $pair) => $pair[0]['command'] === 'player_queues/delete_item')
        ->map(fn (array $pair) => $pair[0]['args']['item_id_or_index'])
        ->values()->all();
    expect($deleted)->toBe(['qi-2', 'qi-1']);
});

it('does nothing when syncQueue is called with an empty list', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(null)]);

    app(MusicClient::class)->syncQueue([]);

    Http::assertNothingSent();
});

it('throws MusicUnavailable from syncQueue and skip on transport failure', function () {
    maConfig();
    Http::fake(['ma:8095/*' => fn () => throw new ConnectionException('down')]);

    expect(fn () => app(MusicClient::class)->syncQueue(['library://track/1']))->toThrow(MusicUnavailable::class);
    expect(fn () => app(MusicClient::class)->skip())->toThrow(MusicUnavailable::class);
});

it('leaves a paused track alone when syncing a list that starts with it', function () {
    maConfig();
    Http::fake(['ma:8095/*' => Http::response(maQueue(['state' => 'idle', 'elapsed_time' => 12.8]))]);

    app(MusicClient::class)->syncQueue(['library://track/5', 'library://track/3']);

    Http::assertSent(fn (Request $r) => $r['command'] === 'player_queues/play_media'
        && $r['args']['option'] === 'replace_next'
        && $r['args']['media'] === ['library://track/3']);
});
