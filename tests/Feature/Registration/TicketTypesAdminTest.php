<?php

use App\Models\User;
use App\Modules\Events\Filament\Resources\Events\Pages\EditEvent;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Filament\RelationManagers\RegistrationsRelationManager;
use App\Modules\Registration\Filament\RelationManagers\TicketTypesRelationManager;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Support\TicketTypeGuard;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ttManager(Event $event): Testable
{
    return Livewire::test(TicketTypesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
}

function regManager(Event $event): Testable
{
    return Livewire::test(RegistrationsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class]);
}

it('creates a ticket type with key and cents and keeps the key on rename', function () {
    $event = Event::factory()->registration()->create();
    $this->actingAs(User::factory()->orga()->create());

    ttManager($event)->callAction(TestAction::make('create')->table(), [
        'name' => 'Flatrate', 'price_cents' => '35,00', 'sort' => 1, 'active' => true,
    ])->assertHasNoFormErrors();

    $type = TicketType::findForEvent($event, 'flatrate');
    expect($type)->not->toBeNull()->and($type->price_cents)->toBe(3500);

    ttManager($event)->callAction(TestAction::make('edit')->table($type), [
        'name' => 'Flatrate Plus', 'price_cents' => '40', 'sort' => 1, 'active' => true,
    ])->assertHasNoFormErrors();

    expect($type->fresh()->key)->toBe('flatrate')
        ->and($type->fresh()->name)->toBe('Flatrate Plus')
        ->and($type->fresh()->price_cents)->toBe(4000);
});

it('rejects an invalid price', function () {
    $event = Event::factory()->registration()->create();
    $this->actingAs(User::factory()->orga()->create());

    ttManager($event)->callAction(TestAction::make('create')->table(), [
        'name' => 'Kaputt', 'price_cents' => 'abc', 'sort' => 0, 'active' => true,
    ])->assertHasFormErrors(['price_cents']);
});

it('refuses to delete a used ticket type but deletes an unused one', function () {
    $event = Event::factory()->registration()->create();
    $used = TicketType::findForEvent($event, 'standard');
    $unused = TicketType::create(['event_id' => $event->id, 'key' => 'extra', 'name' => 'Extra', 'price_cents' => 500, 'sort' => 1, 'active' => true]);
    EventRegistration::factory()->for($event)->create(['ticket_type' => 'standard']);
    $this->actingAs(User::factory()->orga()->create());

    ttManager($event)
        ->assertActionHidden(TestAction::make('delete')->table($used))
        ->assertActionVisible(TestAction::make('delete')->table($unused))
        ->callAction(TestAction::make('delete')->table($unused));

    expect(TicketType::find($unused->id))->toBeNull()
        ->and(TicketType::find($used->id))->not->toBeNull();
});

it('cannot delete or deactivate the last active ticket type', function () {
    $event = Event::factory()->registration()->create();
    $only = TicketType::findForEvent($event, 'standard');
    $this->actingAs(User::factory()->orga()->create());

    ttManager($event)
        ->assertActionHidden(TestAction::make('delete')->table($only))
        ->callAction(TestAction::make('edit')->table($only), [
            'name' => 'Standard', 'price_cents' => '0', 'sort' => 0, 'active' => false,
        ])->assertHasFormErrors(['active']);

    expect($only->fresh()->active)->toBeTrue();

    // With a second active type, the first may be deactivated.
    TicketType::create(['event_id' => $event->id, 'key' => 'zwei', 'name' => 'Zwei', 'price_cents' => 100, 'sort' => 1, 'active' => true]);

    ttManager($event)->callAction(TestAction::make('edit')->table($only), [
        'name' => 'Standard', 'price_cents' => '0', 'sort' => 0, 'active' => false,
    ])->assertHasNoFormErrors();

    expect($only->fresh()->active)->toBeFalse();
});

it('keeps ticket types and payment settings away from participants', function () {
    $event = Event::factory()->registration()->create();
    $this->actingAs(User::factory()->create());

    expect(TicketTypesRelationManager::canViewForRecord($event, EditEvent::class))->toBeFalse()
        ->and(Gate::allows('update', EventPaymentSettings::forEvent($event)))->toBeFalse()
        ->and(Gate::allows('create', TicketType::class))->toBeFalse();

    $this->get("/admin/events/{$event->getRouteKey()}/edit")->assertForbidden();
});

it('saves valid payment settings and rejects http links', function () {
    $event = Event::factory()->registration()->create();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('editPaymentSettings', [
            'payment_link' => 'http://paypal.me/example-orga', 'payment_note' => '', 'reminder_after_days' => 3, 'reminder_before_event_days' => 3,
        ])->assertHasFormErrors(['payment_link']);

    expect(EventPaymentSettings::query()->where('event_id', $event->id)->exists())->toBeFalse();

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('editPaymentSettings', [
            'payment_link' => 'https://paypal.me/example-orga', 'payment_note' => 'Bitte Namen angeben', 'reminder_after_days' => 0, 'reminder_before_event_days' => 60,
        ])->assertHasNoFormErrors();

    $s = EventPaymentSettings::forEvent($event);
    expect($s->payment_link)->toBe('https://paypal.me/example-orga')
        ->and($s->reminder_after_days)->toBe(0)
        ->and($s->reminder_before_event_days)->toBe(60);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('editPaymentSettings', [
            'payment_link' => null, 'payment_note' => null, 'reminder_after_days' => 61, 'reminder_before_event_days' => 3,
        ])->assertHasFormErrors(['reminder_after_days']);
});

it('records who paid when toggling paid and clears it again', function () {
    $event = Event::factory()->registration()->create();
    $registration = EventRegistration::factory()->for($event)->create();
    $this->actingAs(User::factory()->orga()->create());

    regManager($event)->callTableAction('toggle_paid', $registration, ['paid_by' => 'Max']);

    expect($registration->fresh()->paid_at)->not->toBeNull()->and($registration->fresh()->paid_by)->toBe('Max');

    regManager($event)->callTableAction('toggle_paid', $registration);

    expect($registration->fresh()->paid_at)->toBeNull()->and($registration->fresh()->paid_by)->toBeNull();
});

it('re-prices unpaid registrations in bulk and skips paid ones', function () {
    $event = Event::factory()->registration()->create();
    TicketType::findForEvent($event, 'standard')->update(['price_cents' => 2500]);
    $a = EventRegistration::factory()->for($event)->create();
    $b = EventRegistration::factory()->for($event)->create();
    $c = EventRegistration::factory()->for($event)->create();
    foreach ([$a, $b, $c] as $r) {
        $r->ticket_price_cents = 0;
        $r->save();
    }
    $c->paid_at = now();
    $c->save();
    $this->actingAs(User::factory()->orga()->create());

    regManager($event)
        ->callTableBulkAction('rePrice', [$a, $b, $c])
        ->assertNotified('2 aktualisiert, 1 übersprungen (bezahlt)');

    expect($a->fresh()->ticket_price_cents)->toBe(2500)
        ->and($b->fresh()->ticket_price_cents)->toBe(2500)
        ->and($c->fresh()->ticket_price_cents)->toBe(0);
});

it('re-snapshots the price when the ticket type of a registration is changed', function () {
    $event = Event::factory()->registration()->create();
    TicketType::create(['event_id' => $event->id, 'key' => 'flatrate', 'name' => 'Flatrate', 'price_cents' => 3500, 'sort' => 1, 'active' => true]);
    $registration = EventRegistration::factory()->for($event)->create();
    $this->actingAs(User::factory()->orga()->create());

    regManager($event)->callTableAction('change_ticket', $registration, ['ticket_type' => 'flatrate']);

    expect($registration->fresh()->ticket_type)->toBe('flatrate')
        ->and($registration->fresh()->ticket_price_cents)->toBe(3500);
});

it('exports price and paid_by and filters open payments', function () {
    $event = Event::factory()->registration()->create();
    $open = EventRegistration::factory()->for($event)->for(User::factory()->create(['name' => 'Offen Olli']))->create();
    $free = EventRegistration::factory()->for($event)->for(User::factory()->create(['name' => 'Gratis Gerd']))->create();
    $paid = EventRegistration::factory()->for($event)->for(User::factory()->create(['name' => 'Bezahlt Bea']))->create();
    $open->ticket_price_cents = 3500;
    $open->save();
    $free->ticket_price_cents = 0;
    $free->save();
    $paid->ticket_price_cents = 3500;
    $paid->paid_at = now();
    $paid->paid_by = 'Max';
    $paid->save();
    $this->actingAs(User::factory()->orga()->create());

    $test = regManager($event)->callTableAction('export_csv');
    $content = base64_decode(data_get($test->effects, 'download.content'));
    expect($content)->toContain('ticket_price_eur')->toContain('paid_by')->toContain('35.00')->toContain('Max');

    regManager($event)->filterTable('payment_open')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$free, $paid]);
});

it('guards deletion server-side: used, last active, and unused with another active type', function () {
    $event = Event::factory()->registration()->create();
    $standard = TicketType::findForEvent($event, 'standard');
    $other = TicketType::create(['event_id' => $event->id, 'key' => 'other', 'name' => 'Other', 'price_cents' => 100, 'sort' => 1, 'active' => true]);

    expect(TicketTypeGuard::canDelete($other))->toBeTrue();

    EventRegistration::factory()->for($event)->create(['ticket_type' => 'other']);
    expect(TicketTypeGuard::canDelete($other))->toBeFalse();

    $other->delete();
    expect(TicketTypeGuard::canDelete($standard))->toBeFalse();
});
