<?php

use App\Models\User;
use App\Modules\Catering\Actions\MarkUserItemsPaid;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Actions\RecomputeIncludedItems;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Enums\FoodPaymentMethod;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\EditFoodOrder;
use App\Modules\Catering\Filament\Resources\FoodOrders\RelationManagers\ItemsRelationManager;
use App\Modules\Catering\Filament\Resources\FoodOrders\Schemas\FoodOrderForm;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\CostSplit;
use App\Modules\Catering\Support\FoodIncluded;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ticketHolder(Event $event, string $ticketKey): User
{
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create(['ticket_type' => $ticketKey]);

    return $user;
}

function paymentOrder(array $attrs = []): FoodOrder
{
    $event = Event::factory()->create();
    foreach (['flatrate' => 'Flatrate', 'basic' => 'Basic'] as $key => $name) {
        TicketType::query()->updateOrCreate(['event_id' => $event->id, 'key' => $key], ['name' => $name, 'price_cents' => 0]);
    }

    return FoodOrder::factory()->open()->for($event)->create(array_merge(['included_ticket_types' => ['flatrate']], $attrs));
}

it('marks an item included for a flatrate holder only', function () {
    $order = paymentOrder();
    $flat = ticketHolder($order->event, 'flatrate');
    $basic = ticketHolder($order->event, 'basic');

    $a = app(PlaceFoodOrderItem::class)->handle($order, $flat, 'pizza_margherita');
    $b = app(PlaceFoodOrderItem::class)->handle($order, $basic, 'pizza_margherita');

    expect($a->fresh()->included)->toBeTrue()->and($b->fresh()->included)->toBeFalse();
});

it('recomputes unpaid items on open rounds but leaves paid ones', function () {
    $order = paymentOrder(['included_ticket_types' => []]);
    $flat = ticketHolder($order->event, 'flatrate');
    $unpaid = app(PlaceFoodOrderItem::class)->handle($order, $flat, 'pizza_margherita');
    $paid = app(PlaceFoodOrderItem::class)->handle($order, $flat, 'pizza_margherita');
    $paid->paid_at = now();
    $paid->save();

    $order->update(['included_ticket_types' => ['flatrate']]);

    expect(app(RecomputeIncludedItems::class)->handle($order->fresh()))->toBe(1)
        ->and($unpaid->fresh()->included)->toBeTrue()
        ->and($paid->fresh()->included)->toBeFalse();
});

it('does not recompute a closed round', function () {
    $order = paymentOrder(['included_ticket_types' => []]);
    $flat = ticketHolder($order->event, 'flatrate');
    $item = app(PlaceFoodOrderItem::class)->handle($order, $flat, 'pizza_margherita');
    $order->update(['included_ticket_types' => ['flatrate']]);
    $order->status = FoodOrderStatus::Closed;
    $order->save();

    expect(app(RecomputeIncludedItems::class)->handle($order->fresh()))->toBe(0)
        ->and($item->fresh()->included)->toBeFalse();
});

it('recomputes after the orga saves the edit form', function () {
    $order = paymentOrder(['included_ticket_types' => []]);
    $flat = ticketHolder($order->event, 'flatrate');
    $item = app(PlaceFoodOrderItem::class)->handle($order, $flat, 'pizza_margherita');

    $this->actingAs(User::factory()->orga()->create());
    Livewire::test(EditFoodOrder::class, ['record' => $order->getKey()])
        ->fillForm(['included_ticket_types' => ['flatrate']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->included)->toBeTrue();
});

it('sums total, included, due and paid per person', function () {
    $order = paymentOrder();
    $user = User::factory()->create(['name' => 'Anna']);
    foreach ([[950, true, false], [450, false, false], [850, false, true]] as [$price, $inc, $paid]) {
        $item = FoodOrderItem::factory()->for($order, 'foodOrder')->for($user)->create(['price_cents' => $price]);
        $item->included = $inc;
        $item->paid_at = $paid ? now() : null;
        $item->save();
    }

    expect(CostSplit::for($order)['perUser'][$user->id])->toBe([
        'name' => 'Anna', 'totalCents' => 2250, 'includedCents' => 950, 'dueCents' => 450, 'paidCents' => 850,
    ]);
});

it('marks only that users due items as paid', function () {
    $order = paymentOrder();
    $anna = User::factory()->create();
    $ben = User::factory()->create();
    $due = FoodOrderItem::factory()->for($order, 'foodOrder')->for($anna)->create();
    $inc = FoodOrderItem::factory()->for($order, 'foodOrder')->for($anna)->create();
    $inc->included = true;
    $inc->save();
    $other = FoodOrderItem::factory()->for($order, 'foodOrder')->for($ben)->create();

    expect(app(MarkUserItemsPaid::class)->handle($order, $anna->id))->toBe(1)
        ->and($due->fresh()->paid_at)->not->toBeNull()
        ->and($inc->fresh()->paid_at)->toBeNull()
        ->and($other->fresh()->paid_at)->toBeNull();
});

it('offers the all-paid action to orga in the relation manager', function () {
    $order = paymentOrder();
    $anna = User::factory()->create();
    $item = FoodOrderItem::factory()->for($order, 'foodOrder')->for($anna)->create();

    $this->actingAs(User::factory()->orga()->create());
    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditFoodOrder::class])
        ->callTableAction('mark_user_paid', $item);

    expect($item->fresh()->paid_at)->not->toBeNull();
});

it('rejects an http payment link and accepts https', function () {
    $order = paymentOrder();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditFoodOrder::class, ['record' => $order->getKey()])
        ->fillForm(['payment_method' => 'paypal', 'payment_link' => 'http://paypal.me/example-orga'])
        ->call('save')
        ->assertHasFormErrors(['payment_link']);

    Livewire::test(EditFoodOrder::class, ['record' => $order->getKey()])
        ->fillForm(['payment_method' => 'paypal', 'payment_link' => 'https://paypal.me/example-orga'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->fresh()->payment_method)->toBe(FoodPaymentMethod::Paypal);
});

it('offers all event ticket types as form options, marking inactive ones', function () {
    $order = paymentOrder();
    TicketType::query()->where('event_id', $order->event_id)->where('key', 'basic')->update(['active' => false]);

    $options = FoodOrderForm::ticketOptions($order->event_id);

    expect($options)->toHaveKeys(['flatrate', 'basic'])->and($options['basic'])->toBe('Basic (inaktiv)');
});

it('treats a viewer without registration as not included', function () {
    $order = paymentOrder();

    expect(FoodIncluded::for($order, null))->toBeFalse()
        ->and(FoodIncluded::for($order, 'basic'))->toBeFalse()
        ->and(FoodIncluded::for($order, 'flatrate'))->toBeTrue();
});
