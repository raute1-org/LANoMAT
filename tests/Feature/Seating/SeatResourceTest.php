<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Seating\Models\Seat;

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
