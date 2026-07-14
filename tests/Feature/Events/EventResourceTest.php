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
        ->assertActionDoesNotExist('transition_registration')
        ->assertActionDoesNotExist('transition_live')
        ->assertActionDoesNotExist('transition_finished')
        ->assertActionDoesNotExist('transition_archived');
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
        ->assertActionDoesNotExist('transition_draft')
        ->assertActionDoesNotExist('transition_announced')
        ->assertActionDoesNotExist('transition_registration')
        ->assertActionDoesNotExist('transition_live')
        ->assertActionDoesNotExist('transition_finished')
        ->assertActionDoesNotExist('transition_archived');
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
