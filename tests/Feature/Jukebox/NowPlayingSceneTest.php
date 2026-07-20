<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fills data.track and data.upNext with public track metadata only for a now-playing scene', function () {
    $event = Event::factory()->live()->create();
    $adder = User::factory()->create(['name' => 'Ada']);

    JukeboxItem::factory()->for($event)->for($adder, 'addedBy')->create([
        'status' => QueueItemStatus::Playing,
        'title' => 'Song A',
        'artist' => 'Artist A',
        'image_url' => 'https://example.test/cover-a.jpg',
    ]);

    JukeboxItem::factory()->for($event)->for($adder, 'addedBy')->create([
        'status' => QueueItemStatus::Queued,
        'title' => 'Song B',
        'artist' => 'Artist B',
        'image_url' => 'https://example.test/cover-b.jpg',
    ]);

    JukeboxItem::factory()->for($event)->for($adder, 'addedBy')->create([
        'status' => QueueItemStatus::Queued,
        'title' => 'Song C',
        'artist' => null,
        'image_url' => null,
    ]);

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::NowPlaying,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['type'])->toBe('now_playing');
    expect($payload['data'])->toHaveKeys(['track', 'upNext']);

    expect($payload['data']['track'])->toMatchArray([
        'title' => 'Song A',
        'artist' => 'Artist A',
        'imageUrl' => 'https://example.test/cover-a.jpg',
    ]);
    expect($payload['data']['track'])->not->toHaveKey('addedByName');
    expect($payload['data']['track'])->not->toHaveKey('voteCount');
    expect($payload['data']['track'])->not->toHaveKey('id');

    expect($payload['data']['upNext'])->toHaveCount(2);
    expect($payload['data']['upNext'][0])->toMatchArray([
        'title' => 'Song B',
        'artist' => 'Artist B',
        'imageUrl' => 'https://example.test/cover-b.jpg',
    ]);
    expect($payload['data']['upNext'][0])->not->toHaveKey('addedByName');
    expect($payload['data']['upNext'][0])->not->toHaveKey('voteCount');
    expect($payload['data']['upNext'][0])->not->toHaveKey('hasVoted');
    expect($payload['data']['upNext'][1])->toMatchArray([
        'title' => 'Song C',
        'artist' => null,
        'imageUrl' => null,
    ]);
});

it('returns a null track and empty upNext when nothing is playing or queued yet', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::NowPlaying,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['track'])->toBeNull();
    expect($payload['data']['upNext'])->toBe([]);
});

it('caps upNext at the top 5 upcoming tracks', function () {
    $event = Event::factory()->live()->create();
    $adder = User::factory()->create();

    JukeboxItem::factory()->for($event)->for($adder, 'addedBy')->count(7)->create([
        'status' => QueueItemStatus::Queued,
    ]);

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::NowPlaying,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['upNext'])->toHaveCount(5);
});
