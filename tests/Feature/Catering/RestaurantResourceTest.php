<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Filament\Livewire\RestaurantMenuTable;
use App\Modules\Catering\Filament\Resources\Restaurants\Pages\CreateRestaurant;
use App\Modules\Catering\Filament\Resources\Restaurants\Pages\EditRestaurant;
use App\Modules\Catering\Filament\Resources\Restaurants\Pages\ListRestaurants;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Models\Restaurant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->orga = User::factory()->orga()->create();
    $this->actingAs($this->orga);
});

function menuRestaurant(): Restaurant
{
    return Restaurant::factory()->withMenu([
        new MenuOption('pizza-margherita', 'Pizza: Margherita', 850, 'Tomate, Käse'),
        new MenuOption('pizza-salami', 'Pizza: Salami', 950),
        new MenuOption('salat', 'Salat: Gemischt', 450),
    ])->create();
}

it('lists restaurants with dish and round counts', function () {
    $restaurant = menuRestaurant();
    FoodOrder::factory()->withRestaurant($restaurant)->create();

    Livewire::test(ListRestaurants::class)
        ->assertCanSeeTableRecords([$restaurant])
        ->assertSeeText('3');
});

it('creates a restaurant with a generated slug and rejects bad links', function () {
    Livewire::test(CreateRestaurant::class)
        ->fillForm(['name' => 'Beispiel Imbiss', 'slug' => 'beispiel-imbiss', 'website' => 'http://insecure.example'])
        ->call('create')
        ->assertHasFormErrors(['website']);

    Livewire::test(CreateRestaurant::class)
        ->fillForm([
            'name' => 'Beispiel Imbiss',
            'slug' => 'beispiel-imbiss',
            'phone' => '000 00000',
            'default_payment_method' => 'paypal',
            'default_payment_link' => 'https://paypal.me/example-orga',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $restaurant = Restaurant::query()->where('slug', 'beispiel-imbiss')->firstOrFail();
    expect($restaurant->menu)->toBe([])
        ->and($restaurant->default_payment_method->value)->toBe('paypal');
});

it('edits master data and keeps the menu out of the form save', function () {
    $restaurant = menuRestaurant();

    Livewire::test(EditRestaurant::class, ['record' => $restaurant->getKey()])
        ->fillForm(['description' => 'Kurz und knapp'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($restaurant->fresh()->description)->toBe('Kurz und knapp')
        ->and($restaurant->fresh()->menu)->toHaveCount(3);
});

describe('menu editor', function () {
    it('groups by category, searches and filters', function () {
        $restaurant = menuRestaurant();

        Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $restaurant->id])
            ->assertCanSeeTableRecords(['pizza-margherita', 'pizza-salami', 'salat'])
            ->searchTable('salami')
            ->assertCanSeeTableRecords(['pizza-salami'])
            ->assertCanNotSeeTableRecords(['salat'])
            ->searchTable('')
            ->filterTable('category', 'Salat')
            ->assertCanSeeTableRecords(['salat'])
            ->assertCanNotSeeTableRecords(['pizza-margherita']);
    });

    it('adds a dish with a generated key and a euro price', function () {
        $restaurant = menuRestaurant();

        Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $restaurant->id])
            ->callAction(TestAction::make('add')->table(), ['name' => 'Pasta: Carbonara', 'price' => '9,90', 'description' => 'Ei, Speck'])
            ->assertHasNoActionErrors();

        $added = collect($restaurant->fresh()->menu)->firstWhere('key', 'pasta-carbonara');
        expect($added->priceCents)->toBe(990)->and($added->description)->toBe('Ei, Speck');
    });

    it('validates price format, NUL bytes and length', function () {
        $restaurant = menuRestaurant();

        Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $restaurant->id])
            ->callAction(TestAction::make('add')->table(), ['name' => "Bad\0Name", 'price' => 'abc'])
            ->assertHasActionErrors(['name', 'price']);

        expect($restaurant->fresh()->menu)->toHaveCount(3);
    });

    it('edits a dish without changing its key', function () {
        $restaurant = menuRestaurant();

        Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $restaurant->id])
            ->callTableAction('edit', 'pizza-salami', ['name' => 'Pizza: Salami scharf', 'price' => '10,00', 'description' => ''])
            ->assertHasNoActionErrors();

        $dish = collect($restaurant->fresh()->menu)->firstWhere('key', 'pizza-salami');
        expect($dish->name)->toBe('Pizza: Salami scharf')->and($dish->priceCents)->toBe(1000);
    });

    it('protects ordered dishes from deletion and offers repricing for open items', function () {
        $restaurant = menuRestaurant();
        $round = FoodOrder::factory()->open()->withRestaurant($restaurant)->create();
        $open = FoodOrderItem::factory()->create(['food_order_id' => $round->id, 'selection' => ['option_key' => 'pizza-salami'], 'price_cents' => 950]);
        $paid = FoodOrderItem::factory()->paid()->create(['food_order_id' => $round->id, 'selection' => ['option_key' => 'pizza-salami'], 'price_cents' => 950]);

        $component = Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $restaurant->id]);
        $component->assertTableActionDisabled('delete', 'pizza-salami')
            ->assertTableActionEnabled('delete', 'salat');

        $component->callTableAction('edit', 'pizza-salami', ['name' => 'Pizza: Salami', 'price' => '11,00', 'reprice_open' => true]);

        expect($open->fresh()->price_cents)->toBe(1100)
            ->and($paid->fresh()->price_cents)->toBe(950)
            ->and(collect($restaurant->fresh()->menu)->firstWhere('key', 'pizza-salami')->priceCents)->toBe(1100);
    });

    it('is not available to participants', function () {
        $this->actingAs(User::factory()->create());
        $restaurant = menuRestaurant();

        Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $restaurant->id])->assertForbidden();
    });
});
