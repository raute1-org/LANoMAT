<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Filament\Resources\ScheduleItems\Pages\CreateScheduleItem;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids participants from the schedule items resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/schedule-items')
        ->assertForbidden();
});

it('allows orga into the schedule items resource and renders the list', function () {
    $event = Event::factory()->create();
    ScheduleItem::factory()->for($event)->create(['title' => 'Opening Ceremony']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/schedule-items')
        ->assertOk()
        ->assertSee('Opening Ceremony');
});

it('rejects an end before the start in the admin form', function () {
    $event = Event::factory()->live()->create();
    $this->actingAs(User::factory()->orga()->create());

    Livewire\Livewire::test(CreateScheduleItem::class)
        ->fillForm([
            'event_id' => $event->id,
            'type' => 'custom',
            'title' => 'Backwards',
            'starts_at' => '2026-11-01 12:00:00',
            'ends_at' => '2026-11-01 11:00:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at']);
});
