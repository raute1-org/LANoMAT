<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\DishImageStore;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../WebVoice/NativeClient/Features/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function imageRestaurant(): Restaurant
{
    $dish = app(DishImageStore::class)->store(dishPng());
    $logo = app(DishImageStore::class)->store(dishPng(120, 120, 90));
    $restaurant = Restaurant::factory()->withMenu([new MenuOption('salami', 'Pizza: Salami', 900, null, $dish), new MenuOption('plain', 'Pizza: Plain', 800)])->create();
    $restaurant->forceFill(['logo' => $logo])->save();

    return $restaurant;
}

it('serves restaurant dish images and the logo only while a published round of a visible event uses them', function () {
    $restaurant = imageRestaurant();
    $event = Event::factory()->registration()->create();

    // No round yet: nothing is public.
    $this->get("/catering/restaurant-image/{$restaurant->id}/salami")->assertNotFound();
    $this->get("/catering/restaurant-logo/{$restaurant->id}")->assertNotFound();

    // A draft round does not publish it either.
    FoodOrder::factory()->for($event)->withRestaurant($restaurant)->create();
    $this->get("/catering/restaurant-image/{$restaurant->id}/salami")->assertNotFound();

    FoodOrder::factory()->for($event)->open()->withRestaurant($restaurant)->create();
    $this->get("/catering/restaurant-image/{$restaurant->id}/salami")
        ->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'max-age=86400, public');
    $this->get("/catering/restaurant-logo/{$restaurant->id}")->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get("/catering/restaurant-image/{$restaurant->id}/plain")->assertNotFound();
    $this->get("/catering/restaurant-image/{$restaurant->id}/missing")->assertNotFound();
});

it('keeps images hidden when only a draft event uses the restaurant', function () {
    $restaurant = imageRestaurant();
    FoodOrder::factory()->for(Event::factory()->draft()->create())->open()->withRestaurant($restaurant)->create();

    $this->get("/catering/restaurant-image/{$restaurant->id}/salami")->assertNotFound();
    $this->get("/catering/restaurant-logo/{$restaurant->id}")->assertNotFound();
});

it('serves a dish that is called "logo" as a dish, not as the logo', function () {
    $dish = app(DishImageStore::class)->store(dishPng());
    $restaurant = Restaurant::factory()->withMenu([new MenuOption('logo', 'Logo-Burger', 900, null, $dish)])->create();
    FoodOrder::factory()->for(Event::factory()->registration()->create())->open()->withRestaurant($restaurant)->create();

    $this->get("/catering/restaurant-image/{$restaurant->id}/logo")->assertOk();
    $this->get("/catering/restaurant-logo/{$restaurant->id}")->assertNotFound(); // no logo set
});

it('keeps the old per-round dish image url working as an alias for restaurant rounds', function () {
    $restaurant = imageRestaurant();
    $round = FoodOrder::factory()->for(Event::factory()->registration()->create())->open()->withRestaurant($restaurant)->create();

    $this->get("/catering/dish-image/{$round->id}/salami")->assertOk()->assertHeader('Content-Type', 'image/webp');

    $draft = FoodOrder::factory()->for($round->event)->withRestaurant($restaurant)->create();
    $this->get("/catering/dish-image/{$draft->id}/salami")->assertNotFound();
});

it('gives the page the restaurant header and nothing but name, description and logo', function () {
    $restaurant = imageRestaurant();
    $restaurant->forceFill(['description' => 'Frisch gebacken.', 'phone' => '000 00000', 'address' => 'Teststraße 1', 'website' => 'https://example.test', 'order_url' => 'https://order.example.test', 'notes' => 'nur Orga', 'default_payment_link' => 'https://paypal.me/example-orga'])->save();
    $event = Event::factory()->registration()->create();
    FoodOrder::factory()->for($event)->open()->withRestaurant($restaurant)->create();
    FoodOrder::factory()->for($event)->open()->create(['title' => 'Legacy', 'created_at' => now()->subHour()]);

    $response = $this->actingAs(User::factory()->create())->get("/events/{$event->slug}/catering")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('orders.0.restaurant.name', $restaurant->name)
            ->where('orders.0.restaurant.description', 'Frisch gebacken.')
            ->where('orders.0.restaurant.logoUrl', fn (string $url) => str_contains($url, "/catering/restaurant-logo/{$restaurant->id}") && str_contains($url, 'v='))
            ->where('orders.0.menu.0.imageUrl', fn (string $url) => str_contains($url, "/catering/restaurant-image/{$restaurant->id}/salami"))
            ->where('orders.1.restaurant', null));

    $json = json_encode($response->viewData('page')['props']);
    foreach (['000 00000', 'Teststraße', 'order.example.test', 'nur Orga', 'example.test', 'paypal.me'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('shows the restaurant additively in the native client and keeps contact data out', function () {
    $restaurant = imageRestaurant();
    $restaurant->forceFill(['description' => 'Frisch.', 'phone' => '000 00000', 'notes' => 'nur Orga'])->save();
    $user = User::factory()->create();
    $event = Event::factory()->live()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);
    FoodOrder::factory()->open()->withRestaurant($restaurant)->create(['event_id' => $event->id]);

    $response = $this->getJson("/api/voice-client/v1/events/{$event->slug}/catering", nativeBearer($user))->assertOk();

    expect($response->json('orders.0.restaurant'))->toMatchArray(['name' => $restaurant->name, 'description' => 'Frisch.'])
        ->and(array_keys($response->json('orders.0.restaurant')))->toBe(['name', 'description', 'logo_url'])
        ->and($response->json('orders.0.restaurant.logo_url'))->toContain("/catering/restaurant-logo/{$restaurant->id}")
        ->and($response->json('orders.0.menu.0.image_url'))->toContain("/catering/restaurant-image/{$restaurant->id}/salami")
        ->and($response->getContent())->not->toContain('000 00000')->not->toContain('nur Orga')
        ->and($response->json('api_version') ?? 1)->toBe(1);
});

it('reads the menu from the restaurant in GroupOrder, the order page and keeps order names after the dish is gone', function () {
    $restaurant = Restaurant::factory()->withMenu([new MenuOption('a', 'Gruppe: Alpha', 1000)])->create();
    $event = Event::factory()->live()->create();
    $round = FoodOrder::factory()->open()->withRestaurant($restaurant)->create(['event_id' => $event->id]);
    $user = User::factory()->create();
    EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $user->id]);

    $this->actingAs($user)->post("/food-orders/{$round->id}/items", ['option_key' => 'a'])->assertRedirect();

    expect($round->items()->firstOrFail()->selection['name'])->toBe('Gruppe: Alpha');

    $this->get("/events/{$event->slug}/catering")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('orders.0.menu.0.key', 'a')
        ->where('orders.0.myItems.0.name', 'Gruppe: Alpha'));

    $this->post('/food-orders/'.$round->id.'/items', ['option_key' => 'zzz'])->assertRedirect();
    expect($round->items()->count())->toBe(1);
});
