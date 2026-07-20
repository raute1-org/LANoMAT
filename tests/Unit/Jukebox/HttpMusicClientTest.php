<?php

declare(strict_types=1);

use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Contracts\PlaybackControl;
use App\Modules\Jukebox\Exceptions\MusicUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('maps a Music Assistant search response to TrackDtos', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => [
        ['uri' => 'ma://track/1', 'name' => 'Song', 'artists' => [['name' => 'X']], 'duration' => 200],
    ]])]);

    $tracks = app(MusicClient::class)->search('song');

    expect($tracks[0]->uri)->toBe('ma://track/1')
        ->and($tracks[0]->title)->toBe('Song')
        ->and($tracks[0]->artist)->toBe('X')
        ->and($tracks[0]->durationSeconds)->toBe(200);
});

it('tolerates a search result missing optional fields', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => [
        ['uri' => 'ma://track/2', 'name' => 'Minimal'],
    ]])]);

    $tracks = app(MusicClient::class)->search('song');

    expect($tracks[0]->uri)->toBe('ma://track/2')
        ->and($tracks[0]->title)->toBe('Minimal')
        ->and($tracks[0]->artist)->toBeNull()
        ->and($tracks[0]->durationSeconds)->toBeNull()
        ->and($tracks[0]->imageUrl)->toBeNull();
});

it('sends the search command with the query and limit as JSON-RPC args', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => []])]);

    app(MusicClient::class)->search('song', 5);

    Http::assertSent(fn ($request) => $request->url() === 'http://ma:8095/api'
        && $request->hasHeader('Authorization', 'Bearer t')
        && $request['command'] === 'music/search'
        && $request['args']['query'] === 'song'
        && $request['args']['limit'] === 5);
});

it('throws MusicUnavailable when Music Assistant is unreachable', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => fn () => throw new ConnectionException('down')]);

    expect(fn () => app(MusicClient::class)->search('x'))->toThrow(MusicUnavailable::class);
});

it('throws MusicUnavailable when Music Assistant responds with a server error', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['error' => 'boom'], 500)]);

    expect(fn () => app(MusicClient::class)->search('x'))->toThrow(MusicUnavailable::class);
});

it('reads nowPlaying from the queue items and maps it to a NowPlayingDto', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => [
        'current_item' => [
            'media_item' => ['uri' => 'ma://track/1', 'name' => 'Song', 'artists' => [['name' => 'X']], 'duration' => 200],
            'elapsed_time' => 42,
        ],
        'state' => 'playing',
    ]])]);

    $nowPlaying = app(MusicClient::class)->nowPlaying();

    expect($nowPlaying->uri)->toBe('ma://track/1')
        ->and($nowPlaying->title)->toBe('Song')
        ->and($nowPlaying->positionSeconds)->toBe(42)
        ->and($nowPlaying->isPlaying)->toBeTrue();
});

it('returns null from nowPlaying when nothing is playing', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => ['current_item' => null]])]);

    expect(app(MusicClient::class)->nowPlaying())->toBeNull();
});

it('sends skip as a queue_command with next against the configured player', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => []])]);

    app(MusicClient::class)->skip();

    Http::assertSent(fn ($request) => $request['command'] === 'player_queues/queue_command'
        && $request['args']['queue_id'] === 'hall'
        && $request['args']['command'] === 'next');
});

it('sends pause and resume as queue_command pause/play', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => []])]);

    $client = app(MusicClient::class);
    expect($client)->toBeInstanceOf(PlaybackControl::class);
    $client->pause();
    $client->resume();

    Http::assertSent(fn ($request) => $request['command'] === 'player_queues/queue_command'
        && $request['args']['command'] === 'pause');
    Http::assertSent(fn ($request) => $request['command'] === 'player_queues/queue_command'
        && $request['args']['command'] === 'play');
});

it('syncs the queue by playing the first uri then reordering the rest', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => []])]);

    app(MusicClient::class)->syncQueue(['ma://track/1', 'ma://track/2']);

    Http::assertSent(fn ($request) => $request['command'] === 'player_queues/play_media'
        && $request['args']['queue_id'] === 'hall');
});

it('does not throw when syncQueue is called with an empty list', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => Http::response(['result' => []])]);

    app(MusicClient::class)->syncQueue([]);

    Http::assertNothingSent();
});

it('throws MusicUnavailable from syncQueue and skip on transport failure', function () {
    config(['services.music_assistant' => ['base_url' => 'http://ma:8095', 'token' => 't', 'player_id' => 'hall']]);
    Http::fake(['ma:8095/*' => fn () => throw new ConnectionException('down')]);

    expect(fn () => app(MusicClient::class)->syncQueue(['ma://track/1']))->toThrow(MusicUnavailable::class);
    expect(fn () => app(MusicClient::class)->skip())->toThrow(MusicUnavailable::class);
});
