<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Actions\UpdateRestaurantMenu;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Exceptions\MenuProtectedException;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\GroupOrder;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function restaurantMigration(): object
{
    return require base_path('database/migrations/2026_11_23_100000_create_restaurants_and_link_food_orders.php');
}

function legacyRound(string $title, array $menu, string $method = 'cash', ?string $link = null): int
{
    $round = FoodOrder::factory()->create(['title' => $title]);
    DB::table('food_orders')->where('id', $round->id)->update([
        'menu' => json_encode($menu),
        'payment_method' => $method,
        'payment_link' => $link,
        'restaurant_id' => null,
    ]);

    return $round->id;
}

describe('menuOptions', function () {
    it('reads the legacy column without a restaurant and the restaurant menu with one', function () {
        $legacy = FoodOrder::factory()->create();
        expect(collect($legacy->menuOptions())->pluck('key')->all())->toContain('pizza_margherita');

        $restaurant = Restaurant::factory()->withMenu([new MenuOption('curry', 'Curry', 1000)])->create();
        $round = FoodOrder::factory()->withRestaurant($restaurant)->create();

        expect(collect($round->menuOptions())->pluck('key')->all())->toBe(['curry'])
            ->and($round->restaurant->is($restaurant))->toBeTrue();
    });

    it('refuses to delete a restaurant that a round uses', function () {
        $restaurant = Restaurant::factory()->create();
        FoodOrder::factory()->withRestaurant($restaurant)->create();

        expect(fn () => $restaurant->delete())->toThrow(QueryException::class);
    });
});

describe('migration backfill', function () {
    it('moves a live round to a restaurant 1:1 and leaves items untouched', function () {
        $menu = [['key' => 'dish_a', 'name' => 'Hauptgericht: Alpha', 'price_cents' => 1190, 'description' => 'Test'], ['key' => 'dish_b', 'name' => 'Beilage: Beta', 'price_cents' => 350]];
        $id = legacyRound('Testhaus Beispielstadt', $menu, 'paypal', 'https://paypal.me/example-orga');
        $user = User::factory()->create();
        $item = FoodOrderItem::factory()->create(['food_order_id' => $id, 'user_id' => $user->id, 'selection' => ['option_key' => 'dish_a', 'note' => 'x'], 'price_cents' => 1190]);

        restaurantMigration()->backfill();

        $round = FoodOrder::query()->find($id);
        $restaurant = $round->restaurant;

        expect($restaurant)->not->toBeNull()
            ->and($restaurant->name)->toBe('Testhaus Beispielstadt')
            ->and($restaurant->slug)->toBe('testhaus-beispielstadt')
            ->and($restaurant->default_payment_method->value)->toBe('paypal')
            ->and($restaurant->default_payment_link)->toBe('https://paypal.me/example-orga')
            ->and(array_map(fn (MenuOption $o) => $o->toArray(), $restaurant->menu))->toBe($menu)
            ->and(array_map(fn (MenuOption $o) => $o->toArray(), $round->menu))->toBe($menu);

        $fresh = $item->fresh();
        expect($fresh->selection)->toEqual(['option_key' => 'dish_a', 'note' => 'x'])->and($fresh->price_cents)->toBe(1190);
    });

    it('shares one restaurant between rounds with the same title and menu, and splits otherwise', function () {
        $menu = [['key' => 'a', 'name' => 'A', 'price_cents' => 100]];
        $one = legacyRound('Gleich', $menu);
        $two = legacyRound('Gleich', $menu);
        $other = legacyRound('Gleich', [['key' => 'a', 'name' => 'A', 'price_cents' => 200]]);

        restaurantMigration()->backfill();

        $rid = fn (int $id) => FoodOrder::query()->find($id)->restaurant_id;
        expect($rid($one))->toBe($rid($two))->and($rid($other))->not->toBe($rid($one))
            ->and(Restaurant::query()->pluck('slug')->sort()->values()->all())->toBe(['gleich', 'gleich-2']);
    });

    it('is idempotent', function () {
        legacyRound('Einmal', [['key' => 'a', 'name' => 'A', 'price_cents' => 100]]);

        restaurantMigration()->backfill();
        restaurantMigration()->backfill();

        expect(Restaurant::query()->count())->toBe(1);
    });

    it('rolls back by restoring the legacy menu from the (edited) restaurant', function () {
        $id = legacyRound('Rollback', [['key' => 'a', 'name' => 'A', 'price_cents' => 100]]);
        $migration = restaurantMigration();
        $migration->backfill();

        $restaurant = FoodOrder::query()->find($id)->restaurant;
        app(UpdateRestaurantMenu::class)->handle($restaurant, [new MenuOption('a', 'A', 100), new MenuOption('b', 'B', 200)]);

        $migration->down();

        expect(Schema::hasTable('restaurants'))->toBeFalse()
            ->and(Schema::hasColumn('food_orders', 'restaurant_id'))->toBeFalse()
            ->and(collect(json_decode((string) DB::table('food_orders')->where('id', $id)->value('menu'), true))->pluck('key')->all())->toBe(['a', 'b']);

        $migration->up();
        expect(FoodOrder::query()->find($id)->restaurant_id)->not->toBeNull();
    });
});

describe('order snapshot and menu protection', function () {
    function restaurantRound(): array
    {
        $restaurant = Restaurant::factory()->withMenu([new MenuOption('a', 'Gruppe: Alpha', 1000), new MenuOption('b', 'Gruppe: Beta', 500)])->create();
        $round = FoodOrder::factory()->open()->withRestaurant($restaurant)->create();
        $user = User::factory()->create();
        EventRegistration::factory()->for($round->event)->for($user)->create();

        return [$restaurant, $round, $user];
    }

    it('stores the dish name on the item and still shows it after the dish left the menu', function () {
        [$restaurant, $round, $user] = restaurantRound();
        $item = app(PlaceFoodOrderItem::class)->handle($round, $user, 'a');

        expect($item->selection)->toBe(['option_key' => 'a', 'name' => 'Gruppe: Alpha', 'note' => null]);

        // An unordered dish may go; the ordered one must stay.
        app(UpdateRestaurantMenu::class)->handle($restaurant, [new MenuOption('a', 'Gruppe: Alpha', 1000)]);
        $restaurant->forceFill(['menu' => []])->save();

        $round->refresh()->unsetRelation('restaurant');
        $group = GroupOrder::for($round);
        expect($group->categories[0]['lines'][0]['name'])->toBe('Alpha');
    });

    it('refuses to delete or re-key an ordered dish and reports how many orders', function () {
        [$restaurant, $round, $user] = restaurantRound();
        app(PlaceFoodOrderItem::class)->handle($round, $user, 'a');

        try {
            app(UpdateRestaurantMenu::class)->handle($restaurant, [new MenuOption('a2', 'Gruppe: Alpha', 1000)]);
            $this->fail('expected protection');
        } catch (MenuProtectedException $e) {
            expect($e->blocked)->toBe(['a' => 1]);
        }

        expect(collect($restaurant->fresh()->menu)->pluck('key')->all())->toBe(['a', 'b']);
    });

    it('changes a price without touching items, or reprices only open items on request', function () {
        [$restaurant, $round, $user] = restaurantRound();
        $open = app(PlaceFoodOrderItem::class)->handle($round, $user, 'a');
        $paid = app(PlaceFoodOrderItem::class)->handle($round, $user, 'a');
        $paid->forceFill(['paid_at' => now()])->save();
        $menu = [new MenuOption('a', 'Gruppe: Alpha', 1200), new MenuOption('b', 'Gruppe: Beta', 500)];

        $plain = app(UpdateRestaurantMenu::class)->handle($restaurant, $menu);
        expect($plain)->toBe(['open_affected' => 1, 'repriced' => 0])
            ->and($open->fresh()->price_cents)->toBe(1000);

        $menu[0] = new MenuOption('a', 'Gruppe: Alpha', 1500);
        $with = app(UpdateRestaurantMenu::class)->handle($restaurant, $menu, repriceOpen: true);
        expect($with)->toBe(['open_affected' => 1, 'repriced' => 1])
            ->and($open->fresh()->price_cents)->toBe(1500)
            ->and($paid->fresh()->price_cents)->toBe(1000);
    });

    it('rejects duplicate keys', function () {
        [$restaurant] = restaurantRound();

        expect(fn () => app(UpdateRestaurantMenu::class)->handle($restaurant, [new MenuOption('a', 'A', 1), new MenuOption('a', 'B', 2)]))
            ->toThrow(ValidationException::class);
    });
});
