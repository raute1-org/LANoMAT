<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Filament\Livewire\RestaurantMenuTable;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\Restaurant;
use App\Modules\Catering\Support\DishImageStore;
use App\Modules\Events\Models\Event;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../WebVoice/NativeClient/Features/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function previewRestaurant(): Restaurant
{
    $dish = app(DishImageStore::class)->store(dishPng());
    $logo = app(DishImageStore::class)->store(dishPng(120, 120, 90));
    $restaurant = Restaurant::factory()->withMenu([new MenuOption('salami', 'Pizza: Salami', 900, null, $dish), new MenuOption('plain', 'Pizza: Plain', 800)])->create();
    $restaurant->forceFill(['logo' => $logo])->save();

    return $restaurant;
}

it('serves private previews to orga even while no published round uses the restaurant', function () {
    $restaurant = previewRestaurant();
    $this->actingAs(User::factory()->orga()->create());

    $this->get("/orga/catering/restaurants/{$restaurant->id}/image/salami")
        ->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('Cache-Control', 'max-age=3600, private');
    $this->get("/orga/catering/restaurants/{$restaurant->id}/logo")->assertOk();
    $this->get("/orga/catering/restaurants/{$restaurant->id}/image/plain")->assertNotFound();
    $this->get("/orga/catering/restaurants/{$restaurant->id}/image/missing")->assertNotFound();

    // The public route keeps its visibility rule.
    $this->get("/catering/restaurant-image/{$restaurant->id}/salami")->assertNotFound();
});

it('denies participants and guests the preview routes', function () {
    $restaurant = previewRestaurant();
    FoodOrder::factory()->for(Event::factory()->registration()->create())->open()->withRestaurant($restaurant)->create();

    $this->get("/orga/catering/restaurants/{$restaurant->id}/image/salami")->assertRedirect();
    $this->actingAs(User::factory()->create());
    $this->get("/orga/catering/restaurants/{$restaurant->id}/image/salami")->assertForbidden();
    $this->get("/orga/catering/restaurants/{$restaurant->id}/logo")->assertForbidden();
});

it('shows a thumbnail column pointing at the orga preview route', function () {
    Filament::setCurrentPanel('admin');
    $restaurant = previewRestaurant();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RestaurantMenuTable::class, ['restaurantId' => $restaurant->id])
        ->assertSeeHtml("/orga/catering/restaurants/{$restaurant->id}/image/salami")
        ->assertSeeHtml('loading="lazy"');
});
