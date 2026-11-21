<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\DishImageStore;
use App\Modules\Events\Models\Event;
use App\Support\Images\InvalidImageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function menuJson(array $rows): string
{
    return json_encode($rows, JSON_THROW_ON_ERROR);
}

beforeEach(fn () => Storage::fake('local'));

it('reads an old menu without the new fields', function () {
    $order = FoodOrder::factory()->create();
    DB::table('food_orders')->where('id', $order->id)->update(['menu' => json_encode([
        ['key' => 'a', 'name' => 'Pizza: Salami', 'price_cents' => 900],
    ])]);

    $option = $order->fresh()->menu[0];

    expect($option->description)->toBeNull()
        ->and($option->image)->toBeNull()
        ->and($option->toArray())->toBe(['key' => 'a', 'name' => 'Pizza: Salami', 'price_cents' => 900]);
});

it('stores description and image through the cast and drops foreign image paths', function () {
    $order = FoodOrder::factory()->create();
    $order->menu = [
        ['key' => 'a', 'name' => 'A', 'price_cents' => 100, 'description' => "  Tomaten\n<b>Käse</b> ", 'image' => 'catering/dishes/0123456789abcdef.webp'],
        ['key' => 'b', 'name' => 'B', 'price_cents' => 100, 'image' => '../../.env'],
    ];
    $order->save();

    $menu = $order->fresh()->menu;

    expect($menu[0]->description)->toBe('Tomaten <b>Käse</b>')
        ->and($menu[0]->image)->toBe('catering/dishes/0123456789abcdef.webp')
        ->and($menu[1]->image)->toBeNull();

    expect(fn () => $order->forceFill(['menu' => [new MenuOption('c', 'C', 1, null, '/etc/passwd')]])->save())
        ->toThrow(InvalidArgumentException::class);
});

it('re-encodes to a capped webp named by content hash and dedupes', function () {
    $store = app(DishImageStore::class);

    $path = $store->store(dishPng());
    $again = $store->store(dishPng());

    expect($path)->toMatch('#^catering/dishes/[0-9a-f]{16}\.webp$#')->and($again)->toBe($path);
    Storage::disk('local')->assertExists($path);

    $binary = Storage::disk('local')->get($path);
    $size = getimagesizefromstring($binary);

    expect($size[2])->toBe(IMAGETYPE_WEBP)->and(max($size[0], $size[1]))->toBe(800);
    expect(Storage::disk('local')->allFiles('catering/dishes'))->toHaveCount(1);

    $other = $store->store(dishPng(300, 300, 10));
    expect($other)->not->toBe($path);
});

it('refuses files that are not images', function () {
    $file = tempnam(sys_get_temp_dir(), 'dish');
    file_put_contents($file, 'not an image');

    expect(fn () => app(DishImageStore::class)->store($file))->toThrow(InvalidImageException::class);
});

it('serves a dish image only for a published round of a visible event', function () {
    $event = Event::factory()->registration()->create();
    $path = app(DishImageStore::class)->store(dishPng());
    $menu = [new MenuOption('salami', 'Pizza: Salami', 900, null, $path), new MenuOption('plain', 'Pizza: Plain', 800)];

    $open = FoodOrder::factory()->for($event)->open()->create();
    $open->menu = $menu;
    $open->save();

    $this->get("/catering/dish-image/{$open->id}/salami")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Cache-Control', 'max-age=86400, public');

    $this->get("/catering/dish-image/{$open->id}/plain")->assertNotFound();
    $this->get("/catering/dish-image/{$open->id}/missing")->assertNotFound();

    $draft = FoodOrder::factory()->for($event)->create();
    $draft->menu = $menu;
    $draft->save();
    $this->get("/catering/dish-image/{$draft->id}/salami")->assertNotFound();

    $hidden = Event::factory()->draft()->create();
    $inHidden = FoodOrder::factory()->for($hidden)->open()->create();
    $inHidden->menu = $menu;
    $inHidden->save();
    $this->get("/catering/dish-image/{$inHidden->id}/salami")->assertNotFound();
});

it('gives the page description and a versioned image url', function () {
    $event = Event::factory()->registration()->create();
    $path = app(DishImageStore::class)->store(dishPng());
    $order = FoodOrder::factory()->for($event)->open()->create();
    $order->menu = [
        new MenuOption('a', 'Hähnchen: Chicken Hawaii', 1100, 'Mit Ananas', $path),
        new MenuOption('b', 'Reis: Basmati', 300),
    ];
    $order->save();

    $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/catering")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('orders.0.menu.0.description', 'Mit Ananas')
            ->where('orders.0.menu.0.imageUrl', fn (string $url) => str_contains($url, "/catering/dish-image/{$order->id}/a") && str_contains($url, 'v='.basename($path, '.webp')))
            ->where('orders.0.menu.1.description', null)
            ->where('orders.0.menu.1.imageUrl', null)
        );
});

describe('catering:import-dish-media', function () {
    function mediaRound(): Restaurant
    {
        $order = Restaurant::factory()->create();
        $order->menu = [
            new MenuOption('hawaii', 'Hähnchen: Chicken Hawaii', 1100),
            new MenuOption('curry', 'Lamm: Lamb Curry', 1300),
            new MenuOption('curry-h', 'Hähnchen: Lamb Curry', 1200),
            new MenuOption('reis', 'Reis: Basmati', 300),
        ];
        $order->save();

        return $order;
    }

    function mediaDir(array $files = ['hawaii.png']): string
    {
        $dir = sys_get_temp_dir().'/media-'.bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($files as $i => $f) {
            copy(dishPng(200, 200, 50 + $i * 30), $dir.'/'.$f);
        }

        return $dir;
    }

    it('assigns by category and name, reports leftovers, never touches name or price', function () {
        $order = mediaRound();
        $dir = mediaDir();
        file_put_contents($dir.'/m.json', menuJson([
            ['category' => 'Hähnchen Gerichte', 'name' => 'Chicken Hawaii', 'description' => 'Mit Ananas', 'image_file' => 'hawaii.png'],
            ['category' => 'Hähnchen Gerichte', 'name' => 'Lamb Curry', 'description' => 'Mild', 'image_file' => null],
            ['category' => 'Unbekannt', 'name' => 'Basmati', 'description' => 'Duftreis', 'image_file' => 'missing.png'],
            ['category' => 'Salate', 'name' => 'Gibt es nicht', 'description' => 'x'],
            ['category' => 'X', 'name' => 'Lamb Curry', 'description' => 'mehrdeutig'],
        ]));

        $this->artisan('catering:import-dish-media', ['restaurant' => $order->slug, 'json' => $dir.'/m.json'])
            ->expectsOutputToContain('Ambiguous')
            ->assertFailed(); // one photo file is missing

        $menu = collect($order->fresh()->menu)->keyBy('key');

        expect($menu['hawaii']->description)->toBe('Mit Ananas')
            ->and($menu['hawaii']->image)->toMatch('#^catering/dishes/[0-9a-f]{16}\.webp$#')
            ->and($menu['hawaii']->priceCents)->toBe(1100)
            ->and($menu['curry-h']->description)->toBe('Mild')
            ->and($menu['curry']->description)->toBeNull()
            ->and($menu['reis']->description)->toBe('Duftreis')
            ->and($menu['reis']->image)->toBeNull()
            ->and($menu['reis']->name)->toBe('Reis: Basmati');
    });

    it('resolves the restaurant of a round with --round and accepts the numeric id', function () {
        $restaurant = mediaRound();
        $round = FoodOrder::factory()->withRestaurant($restaurant)->create();
        $dir = mediaDir();
        file_put_contents($dir.'/m.json', menuJson([['category' => 'Hähnchen Gerichte', 'name' => 'Chicken Hawaii', 'description' => 'Per Runde']]));

        $this->artisan('catering:import-dish-media', ['restaurant' => '-', 'json' => $dir.'/m.json', '--round' => (string) $round->id])->assertSuccessful();
        expect($restaurant->fresh()->menu[0]->description)->toBe('Per Runde');

        file_put_contents($dir.'/m.json', menuJson([['category' => 'Hähnchen Gerichte', 'name' => 'Chicken Hawaii', 'description' => 'Per Id']]));
        $this->artisan('catering:import-dish-media', ['restaurant' => (string) $restaurant->id, 'json' => $dir.'/m.json', '--force' => true])->assertSuccessful();
        expect($restaurant->fresh()->menu[0]->description)->toBe('Per Id');

        $legacy = FoodOrder::factory()->create();
        $this->artisan('catering:import-dish-media', ['restaurant' => '-', 'json' => $dir.'/m.json', '--round' => (string) $legacy->id])->assertFailed();
        $this->artisan('catering:import-dish-media', ['restaurant' => 'nope', 'json' => $dir.'/m.json'])->assertFailed();
    });

    it('writes nothing on a dry run', function () {
        $order = mediaRound();
        $dir = mediaDir();
        file_put_contents($dir.'/m.json', menuJson([['category' => 'Hähnchen Gerichte', 'name' => 'Chicken Hawaii', 'description' => 'Mit Ananas', 'image_file' => 'hawaii.png']]));

        $this->artisan('catering:import-dish-media', ['restaurant' => $order->slug, 'json' => $dir.'/m.json', '--dry-run' => true])
            ->expectsOutputToContain('Nothing was written')
            ->assertSuccessful();

        expect($order->fresh()->menu[0]->description)->toBeNull();
        expect(Storage::disk('local')->allFiles('catering/dishes'))->toBe([]);
    });

    it('keeps existing descriptions and photos unless forced', function () {
        $order = mediaRound();
        $order->menu = array_map(fn (MenuOption $o) => $o->key === 'hawaii' ? $o->with('Alt', null) : $o, $order->menu);
        $order->save();
        $dir = mediaDir();
        file_put_contents($dir.'/m.json', menuJson([['category' => 'Hähnchen Gerichte', 'name' => 'Chicken Hawaii', 'description' => 'Neu', 'image_file' => 'hawaii.png']]));

        $this->artisan('catering:import-dish-media', ['restaurant' => $order->slug, 'json' => $dir.'/m.json'])->assertSuccessful();
        expect($order->fresh()->menu[0]->description)->toBe('Alt')->and($order->fresh()->menu[0]->image)->not->toBeNull();

        $this->artisan('catering:import-dish-media', ['restaurant' => $order->slug, 'json' => $dir.'/m.json', '--force' => true])->assertSuccessful();
        expect($order->fresh()->menu[0]->description)->toBe('Neu');
    });

    it('refuses image paths that leave the folder', function () {
        $order = mediaRound();
        $dir = mediaDir();
        copy(dishPng(), dirname($dir).'/outside.png');
        file_put_contents($dir.'/m.json', menuJson([['category' => 'Reis Gerichte', 'name' => 'Basmati', 'image_file' => '../outside.png']]));

        $this->artisan('catering:import-dish-media', ['restaurant' => $order->slug, 'json' => $dir.'/m.json'])->assertFailed();
        expect(collect($order->fresh()->menu)->firstWhere('key', 'reis')->image)->toBeNull();
    });
});
