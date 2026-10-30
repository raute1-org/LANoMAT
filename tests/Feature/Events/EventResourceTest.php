<?php

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Filament\Resources\Events\Pages\CreateEvent;
use App\Modules\Events\Filament\Resources\Events\Pages\EditEvent;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists events for orga in the admin panel', function () {
    Event::factory()->create(['name' => 'Testlan 2026']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/events')
        ->assertOk()
        ->assertSee('Testlan 2026');
});

it('forbids participants from the events resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/events')
        ->assertForbidden();
});

it('only shows the header action for the allowed next status', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertActionExists('transition_announced')
        ->assertActionHidden('transition_registration')
        ->assertActionHidden('transition_live')
        ->assertActionHidden('transition_finished')
        ->assertActionHidden('transition_archived');
});

it('transitions the event status via the header action', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('transition_announced');

    expect($event->fresh()->status)->toBe(EventStatus::Announced);
});

it('shows no transition actions for an archived event', function () {
    $event = Event::factory()->archived()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertActionHidden('transition_announced')
        ->assertActionHidden('transition_registration')
        ->assertActionHidden('transition_live')
        ->assertActionHidden('transition_finished')
        ->assertActionHidden('transition_archived');
});

it('creates an event with an auto-generated slug and draft status', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'name' => 'Fresh LAN 2027',
            'location' => 'Hamburg',
            'starts_at' => '2027-03-01 10:00:00',
            'ends_at' => '2027-03-03 18:00:00',
            'max_participants' => 128,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->where('name', 'Fresh LAN 2027')->firstOrFail();

    expect($event->slug)->toBe('fresh-lan-2027')
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->location)->toBe('Hamburg')
        ->and($event->max_participants)->toBe(128);
});

it('shows the german public-url column label', function () {
    Event::factory()->registration()->create();

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/events')
        ->assertOk()
        ->assertSee('Öffentlicher Link');
});

it('rejects an end before the start and a participant limit below one', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'name' => 'Bad LAN',
            'starts_at' => '2027-03-03 10:00:00',
            'ends_at' => '2027-03-01 18:00:00',
            'max_participants' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at', 'max_participants']);

    expect(Event::query()->where('name', 'Bad LAN')->exists())->toBeFalse();
});

it('accepts an empty participant limit as unlimited', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'name' => 'Open LAN',
            'starts_at' => '2027-03-01 10:00:00',
            'ends_at' => '2027-03-03 18:00:00',
            'max_participants' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::query()->where('name', 'Open LAN')->firstOrFail()->max_participants)->toBeNull();
});

it('offers the next transition right after a transition without a reload', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('transition_announced')
        ->assertActionHidden('transition_announced')
        ->assertActionVisible('transition_registration');
});
