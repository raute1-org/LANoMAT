<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\ShowSceneNow;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\ListInfoscreenScenes;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('dispatches a SceneOverride on the event channel when a helper shows a scene now via the control endpoint', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $scene = InfoscreenScene::factory()->for($event)->announcement()->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/{$scene->id}")
        ->assertRedirect();

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($event, $scene) {
        return $dispatched->eventId === $event->id
            && $dispatched->scene['id'] === $scene->id;
    });
});

it('forbids a plain participant from the control endpoint', function () {
    $event = Event::factory()->live()->create();
    $scene = InfoscreenScene::factory()->for($event)->announcement()->create();

    $this->actingAs(User::factory()->create())
        ->post("/screen/{$event->slug}/control/{$scene->id}")
        ->assertForbidden();
});

it('dispatches SceneOverride from the Filament show_now row action as orga', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $scene = InfoscreenScene::factory()->for($event)->announcement()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListInfoscreenScenes::class)
        ->callTableAction('show_now', $scene);

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($event, $scene) {
        return $dispatched->eventId === $event->id
            && $dispatched->scene['id'] === $scene->id;
    });
});

it('reflects a durationSec override in the dispatched payload', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $scene = InfoscreenScene::factory()->for($event)->announcement()->create(['duration_sec' => 15]);

    app(ShowSceneNow::class)->handle($scene, 42);

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($scene) {
        return $dispatched->scene['id'] === $scene->id
            && $dispatched->scene['durationSec'] === 42;
    });
});

it('builds the ScenePayload projection when dispatching without a durationSec override', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $scene = InfoscreenScene::factory()->for($event)->announcement()->create();

    app(ShowSceneNow::class)->handle($scene);

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($scene) {
        return $dispatched->scene === ScenePayload::for($scene);
    });
});

it('allows show now on a disabled scene by design (no guard)', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $scene = InfoscreenScene::factory()->for($event)->announcement()->disabled()->create();

    app(ShowSceneNow::class)->handle($scene);

    EventFacade::assertDispatched(SceneOverride::class);
});

it('shows a translated control label on the helper control page', function () {
    $event = Event::factory()->live()->create();
    InfoscreenScene::factory()->for($event)->announcement()->create();

    $response = $this->actingAs(User::factory()->helper()->create())
        ->get("/screen/{$event->slug}/control");

    $response->assertInertia(fn ($page) => $page
        ->component('Screen/Control')
        ->where('labels.title', trans('infoscreen.control.title'))
    );
});
