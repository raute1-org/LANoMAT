<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\SetStatusSignal;
use App\Modules\Infoscreen\Enums\StatusLevel;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Events\ScenesUpdated;
use App\Modules\Infoscreen\Models\StatusSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;

uses(RefreshDatabase::class);

it('upserts the signal and dispatches a status SceneOverride with the message when a helper flags internet down', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $helper = User::factory()->helper()->create();

    $signal = app(SetStatusSignal::class)->handle($event, 'internet', StatusLevel::Down, 'Kein Uplink, Techniker ist dran.', $helper);

    expect($signal)->toBeInstanceOf(StatusSignal::class)
        ->and($signal->event_id)->toBe($event->id)
        ->and($signal->component)->toBe('internet')
        ->and($signal->level)->toBe(StatusLevel::Down)
        ->and($signal->message)->toBe('Kein Uplink, Techniker ist dran.');

    expect(StatusSignal::query()->where('event_id', $event->id)->where('component', 'internet')->count())->toBe(1);
    expect(StatusSignal::query()->currentPerComponent()->where('event_id', $event->id)->where('component', 'internet')->first()->level)->toBe(StatusLevel::Down);

    EventFacade::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($event): bool {
        return $dispatched->eventId === $event->id
            && $dispatched->scene['type'] === 'status'
            && $dispatched->scene['data']['component'] === 'internet'
            && $dispatched->scene['data']['level'] === StatusLevel::Down->value
            && $dispatched->scene['data']['message'] === 'Kein Uplink, Techniker ist dran.';
    });
});

it('upserts (not inserts) the component signal on a second call, so the latest row per component is current', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $helper = User::factory()->helper()->create();

    app(SetStatusSignal::class)->handle($event, 'internet', StatusLevel::Degraded, 'Langsam.', $helper);
    app(SetStatusSignal::class)->handle($event, 'internet', StatusLevel::Down, 'Jetzt ganz aus.', $helper);

    // Append-only history — both reports exist as rows — but exactly one is
    // "current" per (event, component): the latest.
    expect(StatusSignal::query()->where('event_id', $event->id)->where('component', 'internet')->count())->toBe(2);

    $current = StatusSignal::query()->where('event_id', $event->id)->currentPerComponent()->where('component', 'internet')->first();

    expect($current->level)->toBe(StatusLevel::Down)
        ->and($current->message)->toBe('Jetzt ganz aus.');
});

it('dispatches no outage override when returning a component to ok, only a scenes.updated reload', function () {
    EventFacade::fake([SceneOverride::class, ScenesUpdated::class]);

    $event = Event::factory()->live()->create();
    $helper = User::factory()->helper()->create();

    app(SetStatusSignal::class)->handle($event, 'internet', StatusLevel::Down, 'Kein Uplink.', $helper);

    EventFacade::assertDispatched(SceneOverride::class);
    EventFacade::assertNotDispatched(ScenesUpdated::class);

    app(SetStatusSignal::class)->handle($event, 'internet', StatusLevel::Ok, null, $helper);

    EventFacade::assertDispatchedTimes(SceneOverride::class, 1);
    EventFacade::assertDispatched(ScenesUpdated::class, fn (ScenesUpdated $dispatched): bool => $dispatched->eventId === $event->id);

    $current = StatusSignal::query()->currentPerComponent()->where('event_id', $event->id)->where('component', 'internet')->first();

    expect($current->level)->toBe(StatusLevel::Ok);
});

it('403s a participant setting a status signal', function () {
    $event = Event::factory()->live()->create();
    $participant = User::factory()->create();

    expect(fn () => app(SetStatusSignal::class)->handle($event, 'internet', StatusLevel::Down, null, $participant))
        ->toThrow(AuthorizationException::class);
});

it('rejects an unknown component', function () {
    $event = Event::factory()->live()->create();
    $helper = User::factory()->helper()->create();

    expect(fn () => app(SetStatusSignal::class)->handle($event, 'coffee-machine', StatusLevel::Down, null, $helper))
        ->toThrow(InvalidArgumentException::class);
});

it('has German labels for every StatusLevel case', function () {
    expect(StatusLevel::Ok->label())->toBe('OK')
        ->and(StatusLevel::Degraded->label())->toBe('Eingeschränkt')
        ->and(StatusLevel::Down->label())->toBe('Ausgefallen');
});

it('lets a helper set a status signal via the control endpoint and pushes the beamer', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/status", [
            'component' => 'servers',
            'level' => 'down',
            'message' => 'Pelican Panel antwortet nicht.',
        ])
        ->assertRedirect();

    $current = StatusSignal::query()->currentPerComponent()->where('event_id', $event->id)->where('component', 'servers')->first();

    expect($current->level)->toBe(StatusLevel::Down)
        ->and($current->message)->toBe('Pelican Panel antwortet nicht.');

    EventFacade::assertDispatched(SceneOverride::class, fn (SceneOverride $dispatched): bool => $dispatched->eventId === $event->id);
});

it('forbids a plain participant from the status control endpoint', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->create())
        ->post("/screen/{$event->slug}/control/status", [
            'component' => 'internet',
            'level' => 'down',
        ])
        ->assertForbidden();

    expect(StatusSignal::query()->where('event_id', $event->id)->count())->toBe(0);
});

it('422s the status control endpoint for an unknown component', function () {
    $event = Event::factory()->live()->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/status", [
            'component' => 'coffee-machine',
            'level' => 'down',
        ])
        ->assertInvalid(['component']);
});

it('writes the status signal only for the URL event, not a different event a helper might guess at', function () {
    EventFacade::fake([SceneOverride::class]);

    $event = Event::factory()->live()->create();
    $otherEvent = Event::factory()->live()->create();

    $this->actingAs(User::factory()->helper()->create())
        ->post("/screen/{$event->slug}/control/status", [
            'component' => 'voice',
            'level' => 'degraded',
        ])
        ->assertRedirect();

    expect(StatusSignal::query()->where('event_id', $event->id)->where('component', 'voice')->count())->toBe(1)
        ->and(StatusSignal::query()->where('event_id', $otherEvent->id)->count())->toBe(0);
});
