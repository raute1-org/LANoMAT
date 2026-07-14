<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;

function register(Event $event, User $user, string $ticket = 'standard'): EventRegistration
{
    return app(RegisterForEvent::class)->handle($event, $user, $ticket);
}

it('registers a user for an open event as confirmed', function () {
    $event = Event::factory()->registration()->create([
        'settings' => ['tickets' => ['standard', 'early_bird']],
    ]);

    $reg = register($event, User::factory()->create(), 'early_bird');

    expect($reg->status)->toBe(RegistrationStatus::Confirmed)
        ->and($reg->ticket_type)->toBe('early_bird');
});

it('defaults tickets to [standard] when settings has none', function () {
    $event = Event::factory()->registration()->create(['settings' => []]);

    expect(register($event, User::factory()->create(), 'standard')->ticket_type)->toBe('standard');
});

it('rejects an unknown ticket type', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);

    expect(fn () => register($event, User::factory()->create(), 'vip'))
        ->toThrow(RegistrationException::class);
});

it('rejects registration when the event is not in registration status', function () {
    $event = Event::factory()->announced()->create();

    expect(fn () => register($event, User::factory()->create()))
        ->toThrow(RegistrationException::class);
});

it('rejects registration when the participant limit is reached', function () {
    $event = Event::factory()->registration()->create(['max_participants' => 1]);
    register($event, User::factory()->create());

    expect(fn () => register($event, User::factory()->create()))
        ->toThrow(RegistrationException::class);
});

it('does not count cancelled registrations toward the limit', function () {
    $event = Event::factory()->registration()->create(['max_participants' => 1]);
    $first = register($event, User::factory()->create());
    app(CancelRegistration::class)->handle($first);

    expect(register($event, User::factory()->create())->status)->toBe(RegistrationStatus::Confirmed);
});

it('rejects a double registration of the same user', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    register($event, $user);

    expect(fn () => register($event, $user))->toThrow(RegistrationException::class);
});
