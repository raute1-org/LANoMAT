<?php

use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fills data.servers with the event\'s ready server links for a servers scene', function () {
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->create();
    $match = GameMatch::factory()->for($tournament)->create();

    ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.5', port: 27015, connectString: 'steam://connect/203.0.113.5:27015'),
    ]);

    // Not-yet-ready link for the same event: excluded from the beamer scene,
    // mirroring how upcoming_matches only shows Ready matches.
    $pendingMatch = GameMatch::factory()->for($tournament)->create();
    ServerLink::factory()->create([
        'match_id' => $pendingMatch->id,
        'status' => ServerLinkStatus::Provisioning,
    ]);

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::Servers,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data'])->toHaveKey('servers');
    expect($payload['data']['servers'])->toHaveCount(1);
    expect($payload['data']['servers'][0])->toMatchArray([
        'address' => '203.0.113.5',
        'port' => 27015,
        'status' => 'ready',
    ]);
});

it('returns an empty servers list when the event has no ready servers yet', function () {
    $event = Event::factory()->live()->create();

    $scene = InfoscreenScene::factory()->for($event)->create([
        'type' => SceneType::Servers,
    ]);

    $payload = ScenePayload::for($scene);

    expect($payload['data']['servers'])->toBe([]);
});
