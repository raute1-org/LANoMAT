<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Models\TombolaPrize;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

it('dispatches a tombola SceneOverride with the prize and winner when a helper draws next', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $prize = TombolaPrize::factory()->for($event)->create(['title' => 'Mechanische Tastatur']);
    $registration = EventRegistration::factory()->for($event)->checkedIn()->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/tombola/{$prize->id}/draw")
        ->assertRedirect();

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($event, $prize, $registration): bool {
        return $dispatched->eventId === $event->id
            && $dispatched->scene['type'] === 'tombola'
            && $dispatched->scene['data']['prize']['title'] === $prize->title
            && $dispatched->scene['data']['winner']['registrationId'] === $registration->id;
    });
});

it('forbids a plain participant from drawing next', function () {
    $event = Event::factory()->live()->create();
    $prize = TombolaPrize::factory()->for($event)->create();
    EventRegistration::factory()->for($event)->checkedIn()->create();

    $this->actingAs(User::factory()->create())
        ->post("/screen/{$event->slug}/control/tombola/{$prize->id}/draw")
        ->assertForbidden();
});

it('404s when the prize in the URL belongs to a different event than the URL event', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $otherEvent = Event::factory()->live()->create();
    $prizeFromOtherEvent = TombolaPrize::factory()->for($otherEvent)->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/tombola/{$prizeFromOtherEvent->id}/draw")
        ->assertNotFound();

    EventFacade::assertNotDispatched(SceneOverride::class);
});

it('shows a translated tombola label on the helper control page', function () {
    $event = Event::factory()->live()->create();
    TombolaPrize::factory()->for($event)->create();

    $response = $this->actingAs(User::factory()->helper()->create())
        ->get("/screen/{$event->slug}/control");

    $response->assertInertia(fn ($page) => $page
        ->component('Screen/Control')
        ->where('triggerLabels.tombola_draw_title', trans('infoscreen.triggers.tombola_draw_title'))
    );
});
