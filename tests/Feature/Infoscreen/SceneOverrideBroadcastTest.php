<?php

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

it('implements ShouldBroadcast and ShouldDispatchAfterCommit', function () {
    $event = new SceneOverride(1, ['id' => 1, 'type' => 'announcement', 'durationSec' => 15, 'config' => [], 'data' => []]);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('broadcasts on the public event.{id} channel with the scene.override name', function () {
    $event = new SceneOverride(42, ['id' => 1, 'type' => 'announcement', 'durationSec' => 15, 'config' => [], 'data' => []]);
    $channel = $event->broadcastOn();

    expect($channel)->toBeInstanceOf(Channel::class)
        ->and($channel->name)->toBe('event.42')
        ->and($event->broadcastAs())->toBe('scene.override');
});

it('carries the scene payload in broadcastWith', function () {
    $scene = ['id' => 7, 'type' => 'announcement', 'durationSec' => 20, 'config' => ['headline' => 'Hi'], 'data' => []];
    $event = new SceneOverride(42, $scene);

    expect($event->broadcastWith())->toBe(['scene' => $scene]);
});

it('dispatches SceneOverride carrying the ScenePayload projection for a scene', function () {
    EventFacade::fake([SceneOverride::class]);

    $eventModel = Event::factory()->live()->create();
    $scene = InfoscreenScene::factory()->for($eventModel)->announcement()->create();

    SceneOverride::dispatch($eventModel->id, ScenePayload::for($scene));

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($eventModel, $scene) {
        return $dispatched->eventId === $eventModel->id
            && $dispatched->scene['id'] === $scene->id
            && $dispatched->scene['type'] === 'announcement';
    });
});
