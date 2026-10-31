<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\CreateFoodOrder;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\EditFoodOrder;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->orga()->create());
    $this->event = Event::factory()->create();
    $this->order = FoodOrder::factory()->for($this->event)->create(['menu' => []]);
});

function saveMenu(array $menu)
{
    return Livewire::test(EditFoodOrder::class, ['record' => test()->order->getRouteKey()])
        ->fillForm(['menu' => $menu])
        ->call('save');
}

// F-R2B-14: the price is entered in EUR (comma or point) and stored as integer cents.

it('stores a EUR price entered with a decimal comma as integer cents', function () {
    saveMenu([['key' => 'pizza', 'name' => 'Pizza', 'price_cents' => '8,50']])->assertHasNoFormErrors();

    expect($this->order->fresh()->menu[0]->priceCents)->toBe(850);
});

it('accepts a decimal point and a whole euro amount', function () {
    saveMenu([
        ['key' => 'a', 'name' => 'A', 'price_cents' => '8.5'],
        ['key' => 'b', 'name' => 'B', 'price_cents' => '9'],
    ])->assertHasNoFormErrors();

    expect(array_map(fn (MenuOption $o) => $o->priceCents, $this->order->fresh()->menu))->toBe([850, 900]);
});

it('accepts exactly the maximum price of 1000,00 EUR', function () {
    saveMenu([['key' => 'big', 'name' => 'Catering-Platte', 'price_cents' => '1000,00']])->assertHasNoFormErrors();

    expect($this->order->fresh()->menu[0]->priceCents)->toBe(100000);
});

it('rejects a price above 1000,00 EUR instead of overflowing the integer column', function () {
    saveMenu([['key' => 'huge', 'name' => 'Zu teuer', 'price_cents' => '21474836,48']])
        ->assertHasFormErrors(['menu.0.price_cents']);

    expect($this->order->fresh()->menu)->toBe([]);
});

it('rejects a negative price', function () {
    saveMenu([['key' => 'neg', 'name' => 'Negativ', 'price_cents' => '-0,50']])
        ->assertHasFormErrors(['menu.0.price_cents']);
});

it('rejects a price with more than two decimals or with letters', function () {
    saveMenu([['key' => 'x', 'name' => 'X', 'price_cents' => '8,505']])->assertHasFormErrors(['menu.0.price_cents']);
    saveMenu([['key' => 'y', 'name' => 'Y', 'price_cents' => 'acht']])->assertHasFormErrors(['menu.0.price_cents']);
});

// Duplicate keys within one menu are forbidden (first one used to win silently).

it('rejects two menu options with the same key', function () {
    saveMenu([
        ['key' => 'pizza', 'name' => 'Pizza Salami', 'price_cents' => '9,50'],
        ['key' => 'pizza', 'name' => 'Pizza Margherita', 'price_cents' => '8,50'],
    ])->assertHasFormErrors(['menu']);

    expect($this->order->fresh()->menu)->toBe([]);
});

it('rejects a menu key longer than 64 characters, the limit of an order option_key', function () {
    saveMenu([['key' => str_repeat('k', 65), 'name' => 'Lang', 'price_cents' => '1,00']])
        ->assertHasFormErrors(['menu.0.key']);
});

it('shows stored cents as a EUR amount with a decimal comma when editing', function () {
    $this->order->menu = [new MenuOption(key: 'pizza', name: 'Pizza', priceCents: 850)];
    $this->order->save();

    $component = Livewire::test(EditFoodOrder::class, ['record' => $this->order->getRouteKey()]);

    expect(collect($component->get('data')['menu'])->first()['price_cents'])->toBe('8,50');
});

// NUL bytes in free text reached PostgreSQL varchar/jsonb and produced a 500 (F-R2B-12, Filament side).

it('rejects a NUL byte in the order title and in a menu name', function () {
    Livewire::test(EditFoodOrder::class, ['record' => $this->order->getRouteKey()])
        ->fillForm(['title' => "Pizza\u{0}"])
        ->call('save')
        ->assertHasFormErrors(['title']);

    saveMenu([['key' => 'p', 'name' => "Pi\u{0}zza", 'price_cents' => '1,00']])
        ->assertHasFormErrors(['menu.0.name']);
});

it('creates an order with EUR prices through the create page', function () {
    Livewire::test(CreateFoodOrder::class)
        ->fillForm([
            'event_id' => $this->event->id,
            'title' => 'Pizza-Bestellung',
            'menu' => [['key' => 'pizza', 'name' => 'Pizza', 'price_cents' => '8,50']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // The beforeEach order shares the factory's default title, so exclude it by id.
    $created = FoodOrder::query()->whereKeyNot($this->order->id)->firstOrFail();

    expect($created->menu[0]->priceCents)->toBe(850);
});
