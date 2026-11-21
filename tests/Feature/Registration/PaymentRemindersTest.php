<?php

use App\Models\User;
use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\PaymentReminderSent;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Notifications\PaymentReminder;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function berlin(string $time): Carbon
{
    return Carbon::parse($time, 'Europe/Berlin')->utc();
}

function reminderRegistration(Carbon $createdAt, ?Carbon $startsAt, string $state = '', int $cents = 2500, string $eventState = 'registration'): EventRegistration
{
    $event = Event::factory()->{$eventState}()->create(['name' => 'Spring LAN', 'starts_at' => $startsAt, 'ends_at' => $startsAt?->copy()->addDays(2)]);
    TicketType::query()->updateOrCreate(['event_id' => $event->id, 'key' => 'standard'], ['name' => 'Standard Seat', 'price_cents' => $cents, 'active' => true]);

    $factory = EventRegistration::factory();
    $factory = $state !== '' ? $factory->{$state}() : $factory;
    $registration = $factory->create(['event_id' => $event->id, 'user_id' => User::factory()->create()->id, 'ticket_type' => 'standard']);
    $registration->forceFill(['ticket_price_cents' => $cents, 'created_at' => $createdAt])->save();

    return $registration->fresh();
}

function runReminders(): void
{
    test()->artisan('registration:payment-reminders')->assertSuccessful();
}

it('sends the after-registration reminder once', function () {
    Notification::fake();
    test()->travelTo(berlin('2026-12-04 10:00'));
    $registration = reminderRegistration(berlin('2026-12-01 09:00'), berlin('2026-12-30 12:00'));

    runReminders();
    runReminders();

    Notification::assertSentToTimes($registration->user, PaymentReminder::class, 1);
    Notification::assertSentTo($registration->user, PaymentReminder::class, fn ($n) => $n->kind === 'after_registration');
});

it('sends the before-event reminder after the first one', function () {
    Notification::fake();
    test()->travelTo(berlin('2026-12-04 10:00'));
    $registration = reminderRegistration(berlin('2026-11-30 09:00'), berlin('2026-12-07 10:00'));
    PaymentReminderSent::query()->create(['event_registration_id' => $registration->id, 'kind' => 'after_registration', 'sent_at' => now()]);

    runReminders();
    runReminders();

    Notification::assertSentToTimes($registration->user, PaymentReminder::class, 1);
    Notification::assertSentTo($registration->user, PaymentReminder::class, fn ($n) => $n->kind === 'before_event');
});

it('sends only the before-event reminder when both are due and records the other', function () {
    Notification::fake();
    test()->travelTo(berlin('2026-12-04 10:00'));
    $registration = reminderRegistration(berlin('2026-12-01 09:00'), berlin('2026-12-06 12:00'));

    runReminders();
    runReminders();

    Notification::assertSentToTimes($registration->user, PaymentReminder::class, 1);
    Notification::assertSentTo($registration->user, PaymentReminder::class, fn ($n) => $n->kind === 'before_event');
    expect(PaymentReminderSent::query()->where('event_registration_id', $registration->id)->pluck('kind')->sort()->values()->all())
        ->toBe(['after_registration', 'before_event']);
});

it('skips paid, cancelled, free and finished or archived cases', function (string $state, int $cents, string $eventState) {
    Notification::fake();
    test()->travelTo(berlin('2026-12-04 10:00'));
    reminderRegistration(berlin('2026-11-20 09:00'), berlin('2026-12-06 12:00'), $state, $cents, $eventState);

    runReminders();

    Notification::assertNothingSent();
})->with([
    'paid' => ['paid', 2500, 'registration'],
    'cancelled' => ['cancelled', 2500, 'registration'],
    'free snapshot' => ['', 0, 'registration'],
    'finished' => ['', 2500, 'finished'],
    'archived' => ['', 2500, 'archived'],
]);

it('only sends between 09:00 and 21:00 Berlin time', function (string $time, bool $sends) {
    Notification::fake();
    test()->travelTo(berlin($time));
    reminderRegistration(berlin('2026-10-20 09:00'), null);

    runReminders();

    $sends ? Notification::assertSentTimes(PaymentReminder::class, 1) : Notification::assertNothingSent();
})->with([
    '08:59' => ['2026-11-25 08:59', false],
    '21:00' => ['2026-11-25 21:00', false],
    '09:00' => ['2026-11-25 09:00', true],
    '20:59' => ['2026-11-25 20:59', true],
    'DST switch Sunday 09:00' => ['2026-10-25 09:00', true],
    'DST switch Sunday 08:59' => ['2026-10-25 08:59', false],
]);

it('uses the database channel and adds Discord only when the module is on', function () {
    $registration = reminderRegistration(berlin('2026-12-01 09:00'), null);
    $notification = new PaymentReminder($registration, 'after_registration');

    app()->instance(Modules::class, new Modules('core'));
    expect($notification->via($registration->user))->toBe(['database']);

    app()->instance(Modules::class, new Modules('full'));
    expect($notification->via($registration->user))->toBe(['database', DiscordChannel::class]);
});

it('sends the Discord DM only to users who keep the payment category on', function () {
    app()->instance(Modules::class, new Modules('full'));
    $fake = fakeDiscord();
    $registration = reminderRegistration(berlin('2026-12-01 09:00'), null);
    $notification = new PaymentReminder($registration, 'after_registration');

    $registration->user->forceFill(['discord_id' => '123456789', 'notification_prefs' => ['payment' => false]])->save();
    app(DiscordChannel::class)->send($registration->user->fresh(), $notification);
    expect($fake->dms)->toBeEmpty();

    $registration->user->forceFill(['notification_prefs' => ['payment' => true]])->save();
    app(DiscordChannel::class)->send($registration->user->fresh(), $notification);
    expect($fake->dms)->toHaveCount(1);
});

it('puts amount, ticket name and the registration URL in the message, not the PayPal link', function () {
    $registration = reminderRegistration(berlin('2026-12-01 09:00'), null);
    EventPaymentSettings::query()->create(['event_id' => $registration->event_id, 'payment_link' => 'https://paypal.me/example-orga']);

    $data = (new PaymentReminder($registration, 'after_registration'))->toDatabase($registration->user);

    expect($data['category'])->toBe('payment')
        ->and($data['body'])->toContain('25,00 €')
        ->toContain('Standard Seat')
        ->toContain(route('events.register', $registration->event))
        ->not->toContain('paypal');
});

it('disables a kind when its day count is 0', function () {
    Notification::fake();
    test()->travelTo(berlin('2026-12-04 10:00'));
    $registration = reminderRegistration(berlin('2026-11-20 09:00'), berlin('2026-12-06 12:00'));
    EventPaymentSettings::query()->create(['event_id' => $registration->event_id, 'reminder_after_days' => 0, 'reminder_before_event_days' => 0]);

    runReminders();

    Notification::assertNothingSent();

    EventPaymentSettings::query()->where('event_id', $registration->event_id)->update(['reminder_before_event_days' => 3]);
    runReminders();

    Notification::assertSentToTimes($registration->user, PaymentReminder::class, 1);
    Notification::assertSentTo($registration->user, PaymentReminder::class, fn ($n) => $n->kind === 'before_event');
});
