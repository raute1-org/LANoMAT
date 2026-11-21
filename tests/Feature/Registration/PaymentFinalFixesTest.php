<?php

use App\Models\User;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Filament\Resources\FoodOrders\Schemas\FoodOrderForm;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\CostSplit;
use App\Modules\Catering\Support\MyFoodPayment;
use App\Modules\Events\Filament\Resources\Events\Pages\EditEvent;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Filament\RelationManagers\RegistrationsRelationManager;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\PaymentReminderSent;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Notifications\PaymentReminder;
use App\Modules\Registration\Support\PaymentLink;
use App\Support\Csv\CsvSafe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fixEvent(): Event
{
    $event = Event::factory()->registration()->create(['name' => 'Fix LAN']);
    TicketType::query()->updateOrCreate(['event_id' => $event->id, 'key' => 'standard'], ['name' => 'Frühbucher inkl. Essen', 'price_cents' => 2500, 'active' => true]);

    return $event;
}

function fixRegistration(Event $event, array $attrs = []): EventRegistration
{
    $registration = EventRegistration::factory()->for($event)->for(User::factory()->create())->create(array_merge(['ticket_type' => 'standard'], $attrs));
    $registration->forceFill(['ticket_price_cents' => 2500])->save();

    return $registration->fresh();
}

it('B1: food payment follows item flags, matching CostSplit, after the include-list is edited', function () {
    $event = fixEvent();
    $registration = fixRegistration($event);
    $order = FoodOrder::factory()->for($event)->create(['status' => FoodOrderStatus::Closed, 'included_ticket_types' => []]);
    $item = new FoodOrderItem(['food_order_id' => $order->id, 'user_id' => $registration->user_id]);
    $item->forceFill(['selection' => ['option_key' => 'pizza_margherita'], 'price_cents' => 850, 'included' => false])->save();

    // Orga edits the include-list after the round closed: the items keep their flag.
    $order->update(['included_ticket_types' => ['standard']]);

    $payment = MyFoodPayment::for($order->fresh(), $event, $registration->user, FoodOrderItem::query()->where('user_id', $registration->user_id)->get());

    expect($payment['dueCents'])->toBe(850)
        ->and($payment['dueCents'])->toBe(CostSplit::for($order->fresh())['perUser'][$registration->user_id]['dueCents']);
});

it('B2: the food order ticket options list inactive types marked inactive', function () {
    $event = fixEvent();
    TicketType::query()->create(['event_id' => $event->id, 'key' => 'old', 'name' => 'Altes Ticket', 'price_cents' => 0, 'active' => false, 'sort' => 5]);

    $options = FoodOrderForm::ticketOptions($event->id);

    expect($options)->toHaveKeys(['standard', 'old'])
        ->and($options['old'])->toBe('Altes Ticket (inaktiv)')
        ->and($options['standard'])->toBe('Frühbucher inkl. Essen');
});

it('B5: the registration page ticket label is the ticket type name', function () {
    $event = fixEvent();
    $registration = fixRegistration($event);

    $this->actingAs($registration->user)
        ->get(route('events.register', $event))
        ->assertInertia(fn ($page) => $page->where('registration.ticketLabel', 'Frühbucher inkl. Essen'));
});

it('B6: reactivation clears reminder history and restarts the registration clock', function () {
    $event = fixEvent();
    $registration = fixRegistration($event);
    PaymentReminderSent::query()->create(['event_registration_id' => $registration->id, 'kind' => 'after_registration', 'sent_at' => now()->subDays(5)]);
    $registration->forceFill(['created_at' => now()->subDays(20)])->save();
    app(CancelRegistration::class)->handle($registration);

    $reactivated = app(RegisterForEvent::class)->handle($event, $registration->user, 'standard');

    expect(PaymentReminderSent::query()->where('event_registration_id', $registration->id)->count())->toBe(0)
        ->and($reactivated->fresh()->created_at->isToday())->toBeTrue();
});

it('B7: a queued reminder is not sent when the registration was paid or cancelled meanwhile', function () {
    $event = fixEvent();
    $paid = fixRegistration($event);
    $cancelled = fixRegistration($event);
    $open = fixRegistration($event);
    $free = fixRegistration($event);
    $free->forceFill(['ticket_price_cents' => 0])->save();

    $paid->forceFill(['paid_at' => now()])->save();
    $cancelled->forceFill(['status' => 'cancelled'])->save();

    $send = fn (EventRegistration $r): bool => (new PaymentReminder($r, 'after_registration'))->shouldSend($r->user, 'database');

    expect($send($paid))->toBeFalse()
        ->and($send($cancelled))->toBeFalse()
        ->and($send($free))->toBeFalse()
        ->and($send($open))->toBeTrue();
});

it('B10: a paypal.me link without a handle is invalid', function () {
    expect(PaymentLink::isValid('https://paypal.me'))->toBeFalse()
        ->and(PaymentLink::isValid('https://www.paypal.me/'))->toBeFalse()
        ->and(PaymentLink::isValid('https://paypal.me/example-orga'))->toBeTrue()
        ->and(PaymentLink::isValid('https://www.paypal.com/pool/example'))->toBeTrue();
});

it('B11: change_ticket is hidden for cancelled registrations', function () {
    $event = fixEvent();
    $cancelled = fixRegistration($event, ['status' => 'cancelled']);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertTableActionHidden('change_ticket', $cancelled);
});

it('B11: change_ticket keeps the price snapshot of a paid registration and re-snapshots an unpaid one', function () {
    $event = fixEvent();
    TicketType::query()->create(['event_id' => $event->id, 'key' => 'vip', 'name' => 'VIP', 'price_cents' => 4000, 'active' => true, 'sort' => 2]);
    $paid = fixRegistration($event);
    $paid->forceFill(['paid_at' => now()])->save();
    $unpaid = fixRegistration($event);

    $this->actingAs(User::factory()->orga()->create());

    $manager = fn () => Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
    $manager()->callTableAction('change_ticket', $paid, ['ticket_type' => 'vip'])->assertNotified(__('registration.admin.change_ticket_done_paid'));
    $manager()->callTableAction('change_ticket', $unpaid, ['ticket_type' => 'vip'])->assertNotified(__('registration.admin.change_ticket_done'));

    expect($paid->fresh()->ticket_type)->toBe('vip')
        ->and($paid->fresh()->ticket_price_cents)->toBe(2500)
        ->and($unpaid->fresh()->ticket_price_cents)->toBe(4000);
});

it('B4: bulk re-price skips a registration whose ticket type row is gone', function () {
    $event = fixEvent();
    $registration = fixRegistration($event, ['ticket_type' => 'ghost']);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->callTableBulkAction('rePrice', [$registration]);

    expect($registration->fresh()->ticket_price_cents)->toBe(2500);
});

it('B12: csv cells starting with a formula character are neutralised', function () {
    expect(CsvSafe::cell('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")
        ->and(CsvSafe::cell('+1'))->toBe("'+1")
        ->and(CsvSafe::cell('Max'))->toBe('Max')
        ->and(CsvSafe::cell(null))->toBe('');
});
