<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Actions\ClaimSeat;
use App\Modules\Seating\Filament\Resources\Seats\Pages\EditSeat;
use App\Modules\Seating\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('warns in the delete modal when the seat is occupied', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();
    $user = User::factory()->create(['name' => 'Alice Example']);
    $reg = EventRegistration::factory()->for($event)->for($user)->create();
    app(ClaimSeat::class)->handle($seat, $reg);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditSeat::class, ['record' => $seat->getRouteKey()])
        ->mountAction('delete')
        ->assertMountedActionModalSee('Alice Example')
        ->assertMountedActionModalSee('belegt von');
});

it('shows no occupancy warning in the delete modal for a free seat', function () {
    $event = Event::factory()->live()->create();
    $seat = Seat::factory()->for($event)->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditSeat::class, ['record' => $seat->getRouteKey()])
        ->mountAction('delete')
        ->assertMountedActionModalDontSee('belegt von');
});
