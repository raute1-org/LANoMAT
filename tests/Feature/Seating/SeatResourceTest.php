<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Seating\Filament\Resources\Seats\Pages\CreateSeat;
use App\Modules\Seating\Filament\Resources\Seats\Pages\EditSeat;
use App\Modules\Seating\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists seats for orga', function () {
    $event = Event::factory()->create();
    Seat::factory()->for($event)->create(['label' => 'A1-1']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/seats')
        ->assertOk()
        ->assertSee('A1-1');
});

it('forbids participants from the seats resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/seats')
        ->assertForbidden();
});

it('shows the german grid-generation label', function () {
    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/seats')
        ->assertOk()
        ->assertSee('Raster anlegen'); // i18n gate
});

it('rejects a duplicate seat label within the same event instead of failing with a 500', function () {
    $event = Event::factory()->create();
    Seat::factory()->for($event)->create(['label' => 'A1-1']);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateSeat::class)
        ->fillForm(['event_id' => $event->id, 'label' => 'A1-1', 'pos_x' => 5, 'pos_y' => 5])
        ->call('create')
        ->assertHasFormErrors(['label' => 'unique']);

    expect(Seat::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('allows the same label in another event and when editing a seat without renaming it', function () {
    $event = Event::factory()->create();
    $other = Event::factory()->create();
    $seat = Seat::factory()->for($event)->create(['label' => 'A1-1']);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateSeat::class)
        ->fillForm(['event_id' => $other->id, 'label' => 'A1-1', 'pos_x' => 1, 'pos_y' => 1])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(EditSeat::class, ['record' => $seat->getRouteKey()])
        ->fillForm(['pos_x' => 9])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('rejects renaming a seat to a label already used in its event', function () {
    $event = Event::factory()->create();
    Seat::factory()->for($event)->create(['label' => 'A1-1']);
    $seat = Seat::factory()->for($event)->create(['label' => 'A1-2']);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditSeat::class, ['record' => $seat->getRouteKey()])
        ->fillForm(['label' => 'A1-1'])
        ->call('save')
        ->assertHasFormErrors(['label' => 'unique']);
});
