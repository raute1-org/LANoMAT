<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Actions\ExportFoodOrderItemsCsv;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Actions\UpdateRestaurantMenu;
use App\Modules\Catering\Domain\MenuChoice;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Exceptions\MenuProtectedException;
use App\Modules\Catering\Filament\Livewire\RestaurantMenuTable;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\GroupOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

require_once __DIR__.'/../WebVoice/NativeClient/Features/Helpers.php';

uses(RefreshDatabase::class);

function setMenuRestaurant(): Restaurant
{
    return Restaurant::factory()->withMenu([
        new MenuOption('menu-2', 'Menü: Menü für 2 Personen', 2490, null, null, [
            new MenuChoice('Hauptgericht', 2, categories: ['Curry']),
            new MenuChoice('Beilage', 1, options: ['reis', 'naan'], allowRepeat: false),
        ]),
        new MenuOption('tikka', 'Curry: Tikka Masala', 1190),
        new MenuOption('palak', 'Curry: Palak Mushroom', 1090),
        new MenuOption('dal', 'Curry: Dal Palak', 990),
        new MenuOption('reis', 'Beilage: Reis', 300),
        new MenuOption('naan', 'Beilage: Naan', 250),
        new MenuOption('cola', 'Getränke: Cola', 250),
    ])->create();
}

/** @return array{0: FoodOrder, 1: User} */
function setMenuRound(?Restaurant $restaurant = null): array
{
    $event = Event::factory()->registration()->create();
    $order = FoodOrder::factory()->for($event)->open()->withRestaurant($restaurant ?? setMenuRestaurant())->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    return [$order, $user];
}

function setMenuPicks(string ...$keys): array
{
    $picks = [];

    foreach ($keys as $key) {
        $picks[] = ['group' => in_array($key, ['reis', 'naan'], true) ? 'Beilage' : 'Hauptgericht', 'option_key' => $key];
    }

    return $picks;
}

it('stores a set menu with name snapshots at the menu price', function () {
    [$order, $user] = setMenuRound();

    $item = app(PlaceFoodOrderItem::class)->handle($order, $user, 'menu-2', 'scharf', setMenuPicks('tikka', 'tikka', 'naan'));

    expect($item->price_cents)->toBe(2490)
        ->and($item->selection['note'])->toBe('scharf')
        ->and($item->selection['choices'])->toBe([
            ['group' => 'Hauptgericht', 'option_key' => 'tikka', 'name' => 'Curry: Tikka Masala'],
            ['group' => 'Hauptgericht', 'option_key' => 'tikka', 'name' => 'Curry: Tikka Masala'],
            ['group' => 'Beilage', 'option_key' => 'naan', 'name' => 'Beilage: Naan'],
        ]);
});

it('refuses incomplete, foreign and forbidden-repeat selections and stores nothing', function (array $picks) {
    [$order, $user] = setMenuRound();

    expect(fn () => app(PlaceFoodOrderItem::class)->handle($order, $user, 'menu-2', null, $picks))->toThrow(CateringException::class);
    expect(FoodOrderItem::query()->count())->toBe(0);
})->with([
    'none' => [[]],
    'one of two' => [fn () => setMenuPicks('tikka', 'naan')],
    'drink as pick' => [fn () => setMenuPicks('tikka', 'cola', 'naan')],
    'repeat forbidden' => [fn () => setMenuPicks('tikka', 'dal', 'naan', 'reis')],
    'set menu as pick' => [fn () => setMenuPicks('tikka', 'menu-2', 'naan')],
]);

it('shows the german message for an incomplete selection on the page', function () {
    [$order, $user] = setMenuRound();

    $this->actingAs($user)->from("/events/{$order->event->slug}/catering")
        ->post("/food-orders/{$order->id}/items", ['option_key' => 'menu-2', 'choices' => setMenuPicks('tikka')])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Bitte wähle für „Hauptgericht“ genau 2 Gerichte (1 gewählt).']);

    expect(FoodOrderItem::query()->count())->toBe(0);
});

it('stores a valid selection over http and ignores choices on an ordinary dish', function () {
    [$order, $user] = setMenuRound();

    $this->actingAs($user)->post("/food-orders/{$order->id}/items", ['option_key' => 'menu-2', 'choices' => setMenuPicks('palak', 'dal', 'reis')])->assertRedirect();
    $this->actingAs($user)->post("/food-orders/{$order->id}/items", ['option_key' => 'cola', 'choices' => setMenuPicks('palak')])->assertRedirect();

    $items = FoodOrderItem::query()->orderBy('id')->get();
    expect($items)->toHaveCount(2)
        ->and($items[0]->selection['choices'])->toHaveCount(3)
        ->and($items[1]->selection)->toEqual(['option_key' => 'cola', 'name' => 'Getränke: Cola', 'note' => null]);
});

it('keeps ordinary dishes unchanged', function () {
    [$order, $user] = setMenuRound();

    $item = app(PlaceFoodOrderItem::class)->handle($order, $user, 'cola');

    expect($item->selection)->toBe(['option_key' => 'cola', 'name' => 'Getränke: Cola', 'note' => null]);
});

it('protects dishes that were picked inside an ordered set menu', function () {
    [$order, $user] = setMenuRound();
    app(PlaceFoodOrderItem::class)->handle($order, $user, 'menu-2', null, setMenuPicks('tikka', 'dal', 'naan'));
    $restaurant = $order->restaurant()->firstOrFail();

    expect($restaurant->orderedCounts())->toBe(['menu-2' => 1])
        ->and($restaurant->protectedCounts())->toMatchArray(['menu-2' => 1, 'tikka' => 1, 'dal' => 1, 'naan' => 1]);

    $without = array_values(array_filter($restaurant->menu, fn (MenuOption $o) => $o->key !== 'dal'));
    expect(fn () => app(UpdateRestaurantMenu::class)->handle($restaurant, $without))->toThrow(MenuProtectedException::class);

    $unused = array_values(array_filter($restaurant->menu, fn (MenuOption $o) => $o->key !== 'cola'));
    app(UpdateRestaurantMenu::class)->handle($restaurant, $unused);
    expect($restaurant->fresh()->menu)->toHaveCount(6);
});

it('rejects a menu write whose groups point at missing dishes', function () {
    $restaurant = setMenuRestaurant();
    $menu = array_values(array_filter($restaurant->menu, fn (MenuOption $o) => $o->key !== 'reis'));

    expect(fn () => app(UpdateRestaurantMenu::class)->handle($restaurant, $menu))->toThrow(ValidationException::class);
    expect($restaurant->fresh()->menu)->toHaveCount(7);
});

it('counts the picks under the menu in the group order and keeps prices on the menu', function () {
    [$order, $user] = setMenuRound();
    $other = User::factory()->create();
    EventRegistration::factory()->for($order->event)->for($other)->create();
    $place = app(PlaceFoodOrderItem::class);
    $place->handle($order, $user, 'menu-2', null, setMenuPicks('palak', 'palak', 'naan'));
    $place->handle($order, $other, 'menu-2', null, setMenuPicks('palak', 'dal', 'reis'));
    $place->handle($order, $other, 'cola');

    $group = GroupOrder::for($order->fresh());
    $menuLine = collect($group->categories)->flatMap(fn ($c) => $c['lines'])->firstWhere('key', 'menu-2');

    expect($menuLine['quantity'])->toBe(2)
        ->and($menuLine['totalCents'])->toBe(4980)
        ->and($menuLine['parts'])->toBe([
            ['name' => 'Palak Mushroom', 'count' => 3],
            ['name' => 'Dal Palak', 'count' => 1],
            ['name' => 'Naan', 'count' => 1],
            ['name' => 'Reis', 'count' => 1],
        ])
        ->and($group->kitchenLine($menuLine))->toBe('2× Menü für 2 Personen: 3× Palak Mushroom, 1× Dal Palak, 1× Naan, 1× Reis')
        ->and($group->text())->toContain('2× Menü für 2 Personen: 3× Palak Mushroom')
        ->and(collect($group->persons)->pluck('dishes')->flatten()->all())->toContain('Menü für 2 Personen (2× Palak Mushroom, Naan)');
});

it('adds an Auswahl column to the CSV only when picks exist', function () {
    [$order, $user] = setMenuRound();
    app(PlaceFoodOrderItem::class)->handle($order, $user, 'cola');
    expect(explode("\r\n", app(ExportFoodOrderItemsCsv::class)->handle($order->fresh()))[0])->not->toContain('Auswahl');

    app(PlaceFoodOrderItem::class)->handle($order, $user, 'menu-2', null, setMenuPicks('palak', 'dal', 'reis'));
    $csv = app(ExportFoodOrderItemsCsv::class)->handle($order->fresh());

    expect($csv)->toContain('Auswahl')->toContain('Curry: Palak Mushroom, Curry: Dal Palak, Beilage: Reis');
});

it('passes the resolved groups and the picks to the page', function () {
    [$order, $user] = setMenuRound();
    app(PlaceFoodOrderItem::class)->handle($order, $user, 'menu-2', null, setMenuPicks('palak', 'dal', 'reis'));

    $this->actingAs($user)->get("/events/{$order->event->slug}/catering")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('orders.0.menu.0.key', 'menu-2')
            ->where('orders.0.menu.0.choices.0.label', 'Hauptgericht')
            ->where('orders.0.menu.0.choices.0.count', 2)
            ->where('orders.0.menu.0.choices.0.allowRepeat', true)
            ->where('orders.0.menu.0.choices.0.options.0', ['key' => 'tikka', 'label' => 'Tikka Masala', 'category' => 'Curry'])
            ->where('orders.0.menu.0.choices.1.options.1.key', 'naan')
            ->where('orders.0.menu.1.choices', [])
            ->where('orders.0.myItems.0.choices.2', ['group' => 'Beilage', 'optionKey' => 'reis', 'name' => 'Beilage: Reis'])
        );
});

it('exposes choices additively in the native client api', function () {
    Carbon::setTestNow('2026-11-02 12:00:00');
    $user = User::factory()->create();
    $headers = nativeBearer($user);
    $event = Event::factory()->live()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);
    $order = FoodOrder::factory()->open()->withRestaurant(setMenuRestaurant())->create(['event_id' => $event->id]);

    $created = $this->postJson("/api/voice-client/v1/food-orders/{$order->id}/items", ['option_key' => 'menu-2', 'choices' => setMenuPicks('palak', 'dal', 'reis')], $headers)->assertCreated();
    expect($created->json('choices'))->toBe([
        ['group' => 'Hauptgericht', 'option_key' => 'palak', 'name' => 'Curry: Palak Mushroom'],
        ['group' => 'Hauptgericht', 'option_key' => 'dal', 'name' => 'Curry: Dal Palak'],
        ['group' => 'Beilage', 'option_key' => 'reis', 'name' => 'Beilage: Reis'],
    ]);

    $this->postJson("/api/voice-client/v1/food-orders/{$order->id}/items", ['option_key' => 'menu-2'], $headers)->assertStatus(422);

    $plain = $this->postJson("/api/voice-client/v1/food-orders/{$order->id}/items", ['option_key' => 'cola'], $headers)->assertCreated();
    expect($plain->json())->not->toHaveKey('choices');

    $catalog = $this->getJson("/api/voice-client/v1/events/{$event->slug}/catering", $headers)->assertOk();
    $menu = collect($catalog->json('orders.0.menu'));
    expect($menu->firstWhere('key', 'menu-2')['choices'][0])->toMatchArray(['label' => 'Hauptgericht', 'count' => 2, 'allow_repeat' => true])
        ->and($menu->firstWhere('key', 'cola'))->not->toHaveKey('choices');
});

describe('menu editor', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->orga()->create());
    });

    it('saves selection groups from the dish modal', function () {
        $restaurant = Restaurant::factory()->withMenu([
            new MenuOption('box', 'Menü: Family Box', 3900),
            new MenuOption('a', 'Curry: A', 1000),
            new MenuOption('b', 'Curry: B', 1100),
            new MenuOption('rice', 'Beilage: Reis', 300),
        ])->create();

        Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $restaurant->id])
            ->callTableAction('edit', 'box', [
                'name' => 'Menü: Family Box',
                'price' => '39,00',
                'choices' => [
                    ['label' => 'Hauptgericht', 'count' => 2, 'source' => 'categories', 'categories' => ['Curry'], 'allow_repeat' => true],
                    ['label' => 'Beilage', 'count' => 1, 'source' => 'options', 'options' => ['rice'], 'allow_repeat' => false],
                ],
            ])
            ->assertHasNoActionErrors();

        $box = collect($restaurant->fresh()->menu)->firstWhere('key', 'box');
        expect($box->choices)->toHaveCount(2)
            ->and($box->choices[0]->categories)->toBe(['Curry'])
            ->and($box->choices[1]->options)->toBe(['rice'])
            ->and($box->choices[1]->allowRepeat)->toBeFalse();
    });
});

it('keeps the groups of a set menu when catering:import-dish-media updates it', function () {
    $restaurant = setMenuRestaurant();
    $dir = sys_get_temp_dir().'/sm-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/m.json', json_encode([['category' => 'Menü', 'name' => 'Menü für 2 Personen', 'description' => 'Zwei Hauptgerichte und eine Beilage', 'image_file' => null]]));

    $this->artisan('catering:import-dish-media', ['restaurant' => $restaurant->slug, 'json' => $dir.'/m.json'])->assertSuccessful();

    $menu = collect($restaurant->fresh()->menu)->firstWhere('key', 'menu-2');
    expect($menu->description)->toBe('Zwei Hauptgerichte und eine Beilage')->and($menu->choices)->toHaveCount(2);
});

it('blocks deleting a picked dish in the editor and says so in german', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->orga()->create());
    [$order, $user] = setMenuRound();
    app(PlaceFoodOrderItem::class)->handle($order, $user, 'menu-2', null, setMenuPicks('tikka', 'dal', 'naan'));

    Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $order->restaurant_id])
        ->assertTableActionDisabled('delete', 'dal')
        ->assertTableActionEnabled('delete', 'cola');
});
