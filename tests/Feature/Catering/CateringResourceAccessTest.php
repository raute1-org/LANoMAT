<?php

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids participants from the food orders resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/food-orders')
        ->assertForbidden();
});

it('allows orga into the food orders resource and renders the list', function () {
    $event = Event::factory()->create();
    FoodOrder::factory()->for($event)->create(['title' => 'Pizza-Bestellung']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/food-orders')
        ->assertOk()
        ->assertSee('Pizza-Bestellung');
});
