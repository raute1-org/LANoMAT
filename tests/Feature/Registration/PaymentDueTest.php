<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Support\PaymentDue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dueRegistration(?string $link, int $cents = 2500, string $state = ''): EventRegistration
{
    $event = Event::factory()->registration()->create(['name' => 'Spring LAN']);
    TicketType::query()->updateOrCreate(['event_id' => $event->id, 'key' => 'standard'], ['name' => 'Standard Seat', 'price_cents' => $cents, 'active' => true]);

    if ($link !== null) {
        EventPaymentSettings::query()->create(['event_id' => $event->id, 'payment_link' => $link, 'payment_note' => 'Als Freunde senden']);
    }

    $factory = EventRegistration::factory();
    $factory = $state !== '' ? $factory->{$state}() : $factory;
    $registration = $factory->create([
        'event_id' => $event->id,
        'user_id' => User::factory()->create(['name' => 'Alex Example'])->id,
        'ticket_type' => 'standard',
    ]);
    $registration->forceFill(['ticket_price_cents' => $cents])->save();

    return $registration->fresh();
}

it('builds link, reference and note for an open registration', function () {
    $due = PaymentDue::forRegistration(dueRegistration('https://paypal.me/example-orga'));

    expect($due['amountCents'])->toBe(2500)
        ->and($due['paid'])->toBeFalse()
        ->and($due['url'])->toBe('https://paypal.me/example-orga/25EUR')
        ->and($due['linkAppendsAmount'])->toBeTrue()
        ->and($due['reference'])->toContain('Spring LAN')->toContain('Alex Example')->toContain('Standard Seat')
        ->and($due['note'])->toBe('Als Freunde senden');
});

it('marks paid registrations paid', function () {
    expect(PaymentDue::forRegistration(dueRegistration('https://paypal.me/example-orga', 2500, 'paid'))['paid'])->toBeTrue();
});

it('has no url without a configured link', function () {
    $due = PaymentDue::forRegistration(dueRegistration(null));

    expect($due['url'])->toBeNull()->and($due['linkAppendsAmount'])->toBeFalse()->and($due['note'])->toBeNull();
});

it('is null for free or cancelled registrations', function () {
    expect(PaymentDue::forRegistration(dueRegistration('https://paypal.me/example-orga', 0)))->toBeNull()
        ->and(PaymentDue::forRegistration(dueRegistration('https://paypal.me/example-orga', 2500, 'cancelled')))->toBeNull();
});

it('leaves pool links unchanged', function () {
    $due = PaymentDue::forRegistration(dueRegistration('https://www.paypal.com/pool/example'));

    expect($due['url'])->toBe('https://www.paypal.com/pool/example')->and($due['linkAppendsAmount'])->toBeFalse();
});

it('returns defaults without writing for events without settings', function () {
    $event = Event::factory()->create();
    $settings = EventPaymentSettings::forEvent($event);

    expect($settings->exists)->toBeFalse()
        ->and($settings->reminder_after_days)->toBe(3)
        ->and($settings->reminder_before_event_days)->toBe(3)
        ->and(EventPaymentSettings::query()->count())->toBe(0);
});
