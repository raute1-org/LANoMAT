<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\EditFoodOrder;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\ListFoodOrders;
use App\Modules\Catering\Filament\Resources\FoodOrders\RelationManagers\ItemsRelationManager;
use App\Modules\Catering\Filament\Resources\FoodOrders\Schemas\FoodOrderForm;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function redesignRound(): array
{
    $order = FoodOrder::factory()->for(Event::factory()->create())->create(['title' => 'Inder-Runde', 'status' => FoodOrderStatus::Open]);
    $order->menu = [new MenuOption('curry', 'Lamm: Chicken Curry', 1250), new MenuOption('cola', 'Getränke: Cola', 300)];
    $order->save();
    $anna = User::factory()->create(['name' => 'Anna']);
    $ben = User::factory()->create(['name' => 'Ben']);
    $a = FoodOrderItem::factory()->for($order, 'foodOrder')->for($anna)->create(['selection' => ['option_key' => 'curry'], 'price_cents' => 1250]);
    $b = FoodOrderItem::factory()->for($order, 'foodOrder')->for($ben)->paid()->create(['selection' => ['option_key' => 'cola'], 'price_cents' => 300]);

    return [$order, $a, $b];
}

it('renders both item tabs with grouping and filters', function () {
    [$order, $a, $b] = redesignRound();
    $this->actingAs(User::factory()->orga()->create());

    $dish = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditFoodOrder::class, 'activeTab' => 'dish'])
        ->assertCanSeeTableRecords([$a, $b]);
    $dish->assertSee('Chicken Curry')->assertSee('Anna');

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditFoodOrder::class, 'activeTab' => 'person'])
        ->assertCanSeeTableRecords([$a, $b])
        ->filterTable('paid', false)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b])
        ->resetTableFilters()
        ->searchTable('Cola')
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a]);
});

it('marks a person as paid from the person tab', function () {
    [$order, $a] = redesignRound();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditFoodOrder::class, 'activeTab' => 'person'])
        ->callTableAction('mark_user_paid', $a);

    expect($a->fresh()->paid_at)->not->toBeNull();
});

it('lists rounds with totals, defaults to non-closed and has row actions', function () {
    [$order] = redesignRound();
    $closed = FoodOrder::factory()->for($order->event)->create(['title' => 'Alt-Runde', 'status' => FoodOrderStatus::Closed]);
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListFoodOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertCanNotSeeTableRecords([$closed])
        ->assertTableColumnStateSet('items_count', 2, $order)
        ->assertTableColumnStateSet('total_cents', 1550, $order)
        ->assertTableColumnStateSet('due_cents', 1250, $order)
        ->callTableAction('close', $order)
        ->filterTable('status', [FoodOrderStatus::Closed->value])
        ->assertCanSeeTableRecords([$closed, $order]);

    expect($order->fresh()->status)->toBe(FoodOrderStatus::Closed);
});

it('shows the status and summary on the edit page', function () {
    [$order] = redesignRound();

    $this->actingAs(User::factory()->orga()->create())
        ->get("/admin/food-orders/{$order->id}/edit")
        ->assertOk()
        ->assertSee('Bestellfenster')
        ->assertSee('Zahlung und Ticket')
        ->assertSee('15,50 €')
        ->assertSee('Gerichte');
});

it('previews the payment link with an example amount', function () {
    expect(FoodOrderForm::paymentPreview('https://paypal.me/example-orga'))->toContain('12,50 €')->toContain('/example-orga/12.5EUR')
        ->and(FoodOrderForm::paymentPreview(''))->toContain('noch kein gültiger Link');
});
