<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\ViewGroupOrder;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function groupOrderRound(): FoodOrder
{
    $order = FoodOrder::factory()->for(Event::factory()->create())->create(['title' => 'Inder-Runde']);
    $order->menu = [new MenuOption('curry', 'Lamm: Chicken Curry', 1250)];
    $order->save();
    $guest = User::factory()->create(['name' => 'Gast <b>Fett</b>']);
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($guest)->create([
        'selection' => ['option_key' => 'curry', 'note' => '=HYPERLINK("x") <script>alert(1)</script>'],
        'price_cents' => 1250,
    ]);

    return $order;
}

it('forbids participants from the group order page', function () {
    $order = groupOrderRound();

    $this->actingAs(User::factory()->create())
        ->get("/admin/food-orders/{$order->id}/group-order")
        ->assertForbidden();
});

it('shows orga the kitchen list and person list with escaped untrusted text', function () {
    $order = groupOrderRound();

    $this->actingAs(User::factory()->orga()->create())
        ->get("/admin/food-orders/{$order->id}/group-order")
        ->assertOk()
        ->assertSee('Chicken Curry')
        ->assertSee('Text kopieren')
        ->assertSee('Drucken')
        ->assertSee('12,50 €')
        ->assertSee('Gast &lt;b&gt;Fett&lt;/b&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('exports the CSV from the page for orga with formula cells neutralised', function () {
    $order = groupOrderRound();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ViewGroupOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('export')
        ->assertFileDownloaded();
});

it('links to the group order from the round edit page', function () {
    $order = groupOrderRound();

    $this->actingAs(User::factory()->orga()->create())
        ->get("/admin/food-orders/{$order->id}/edit")
        ->assertOk()
        ->assertSee('Sammelbestellung');
});
