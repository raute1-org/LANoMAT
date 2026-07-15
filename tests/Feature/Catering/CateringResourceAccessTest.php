<?php

use App\Models\User;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\EditFoodOrder;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

it('round-trips the menu repeater through MenuCast as typed MenuOptions with int prices', function () {
    $event = Event::factory()->create();
    $order = FoodOrder::factory()->for($event)->create(['menu' => []]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditFoodOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm([
            'menu' => [
                ['key' => 'pizza', 'name' => 'Pizza', 'price_cents' => 850],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $order->fresh();

    expect($fresh->menu)->toHaveCount(1)
        ->and($fresh->menu[0]->key)->toBe('pizza')
        ->and($fresh->menu[0]->priceCents)->toBe(850)
        ->and($fresh->menu[0]->priceCents)->toBeInt();
});

it('rejects a negative price_cents on the menu repeater form', function () {
    $event = Event::factory()->create();
    $order = FoodOrder::factory()->for($event)->create(['menu' => []]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditFoodOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm([
            'menu' => [
                ['key' => 'pizza', 'name' => 'Pizza', 'price_cents' => -50],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['menu.0.price_cents']);
});
