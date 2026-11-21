<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Filament\Livewire\RestaurantMenuTable;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\CreateFoodOrder;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\EditFoodOrder;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Events\Models\Event;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->orga()->create());
    $this->event = Event::factory()->create();
    $this->restaurant = Restaurant::factory()->withMenu([])->create();
    $this->order = FoodOrder::factory()->for($this->event)->withRestaurant($this->restaurant)->create();
});

function addDish(array $data)
{
    return Livewire::test(RestaurantMenuTable::class, ['restaurantId' => test()->restaurant->id])
        ->callAction(TestAction::make('add')->table(), $data);
}

// F-R2B-14 (ported to the restaurant menu editor): the price is entered in EUR and stored as integer cents.

it('stores a EUR price entered with a decimal comma as integer cents', function () {
    addDish(['name' => 'Pizza', 'price' => '8,50'])->assertHasNoActionErrors();

    expect($this->restaurant->fresh()->menu[0]->priceCents)->toBe(850);
});

it('accepts a decimal point and a whole euro amount', function () {
    addDish(['name' => 'A', 'price' => '8.5'])->assertHasNoActionErrors();
    addDish(['name' => 'B', 'price' => '9'])->assertHasNoActionErrors();

    expect(array_map(fn (MenuOption $o) => $o->priceCents, $this->restaurant->fresh()->menu))->toBe([850, 900]);
});

it('accepts exactly the maximum price of 1000,00 EUR', function () {
    addDish(['name' => 'Catering-Platte', 'price' => '1000,00'])->assertHasNoActionErrors();

    expect($this->restaurant->fresh()->menu[0]->priceCents)->toBe(100000);
});

it('rejects a price above 1000,00 EUR instead of overflowing the integer column', function () {
    addDish(['name' => 'Zu teuer', 'price' => '21474836,48'])->assertHasActionErrors(['price']);

    expect($this->restaurant->fresh()->menu)->toBe([]);
});

it('rejects a negative price, more than two decimals and letters', function () {
    addDish(['name' => 'Negativ', 'price' => '-0,50'])->assertHasActionErrors(['price']);
    addDish(['name' => 'X', 'price' => '8,505'])->assertHasActionErrors(['price']);
    addDish(['name' => 'Y', 'price' => 'acht'])->assertHasActionErrors(['price']);

    expect($this->restaurant->fresh()->menu)->toBe([]);
});

// Duplicate keys were forbidden (first one used to win silently): keys are now generated unique and fixed.

it('never produces two dishes with the same key', function () {
    addDish(['name' => 'Pizza Salami', 'price' => '9,50']);
    addDish(['name' => 'Pizza Salami', 'price' => '9,50']);

    $keys = array_map(fn (MenuOption $o) => $o->key, $this->restaurant->fresh()->menu);
    expect($keys)->toBe(['pizza-salami', 'pizza-salami-2']);
});

it('shows stored cents as a EUR amount with a decimal comma when editing', function () {
    $this->restaurant->menu = [new MenuOption(key: 'pizza', name: 'Pizza', priceCents: 850)];
    $this->restaurant->save();

    Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $this->restaurant->id])
        ->mountTableAction('edit', 'pizza')
        ->assertSchemaStateSet(['price' => '8,50']);
});

// NUL bytes in free text reached PostgreSQL varchar/jsonb and produced a 500 (F-R2B-12).

it('rejects a NUL byte in the order title and in a dish name', function () {
    Livewire::test(EditFoodOrder::class, ['record' => $this->order->getRouteKey()])
        ->fillForm(['title' => "Pizza\u{0}"])
        ->call('save')
        ->assertHasFormErrors(['title']);

    addDish(['name' => "Pi\u{0}zza", 'price' => '1,00'])->assertHasActionErrors(['name']);
});

it('rejects a description above 600 characters', function () {
    addDish(['name' => 'A', 'price' => '1', 'description' => str_repeat('a', 601)])->assertHasActionErrors(['description']);
});

it('creates a round from a restaurant, prefilling title and payment from its defaults', function () {
    $restaurant = Restaurant::factory()->create(['name' => 'Beispiel Grill']);
    $restaurant->forceFill(['default_payment_method' => 'paypal', 'default_payment_link' => 'https://paypal.me/example-orga'])->save();

    $component = Livewire::test(CreateFoodOrder::class)
        ->fillForm(['event_id' => $this->event->id, 'restaurant_id' => $restaurant->id])
        ->assertSchemaStateSet(['title' => 'Beispiel Grill', 'payment_method' => 'paypal', 'payment_link' => 'https://paypal.me/example-orga']);

    $component->call('create')->assertHasNoFormErrors();

    $created = FoodOrder::query()->where('restaurant_id', $restaurant->id)->firstOrFail();
    expect($created->menuOptions())->toHaveCount(3);
});

it('requires a restaurant for a new round', function () {
    Livewire::test(CreateFoodOrder::class)
        ->fillForm(['event_id' => $this->event->id, 'title' => 'Ohne'])
        ->call('create')
        ->assertHasFormErrors(['restaurant_id']);
});

it('locks the restaurant of a round that already has orders', function () {
    FoodOrderItem::factory()->create(['food_order_id' => $this->order->id, 'selection' => ['option_key' => 'x']]);

    Livewire::test(EditFoodOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertFormFieldIsDisabled('restaurant_id');
});

it('keeps a legacy round without restaurant editable', function () {
    $legacy = FoodOrder::factory()->for($this->event)->create();

    Livewire::test(EditFoodOrder::class, ['record' => $legacy->getRouteKey()])
        ->fillForm(['title' => 'Neu benannt'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($legacy->fresh()->title)->toBe('Neu benannt');
});
