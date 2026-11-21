<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Actions\RegisterForEvent;
use App\Modules\Registration\Exceptions\RegistrationException;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Support\EnsureDefaultTicketType;
use App\Modules\Registration\Support\MyRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ticketType(Event $event, string $key, int $price, bool $active = true, string $name = ''): TicketType
{
    return TicketType::query()->create([
        'event_id' => $event->id,
        'key' => $key,
        'name' => $name !== '' ? $name : ucfirst($key),
        'price_cents' => $price,
        'sort' => 5,
        'active' => $active,
    ]);
}

function signUp(Event $event, User $user, string $ticket): EventRegistration
{
    return app(RegisterForEvent::class)->handle($event, $user, $ticket);
}

it('creates a free standard ticket type when an event is created', function () {
    $event = Event::factory()->registration()->create();

    $types = TicketType::activeFor($event);

    expect($types)->toHaveCount(1)
        ->and($types->first()->key)->toBe('standard')
        ->and($types->first()->price_cents)->toBe(0)
        ->and(EnsureDefaultTicketType::for($event)->id)->toBe($types->first()->id)
        ->and(TicketType::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('snapshots the ticket price at registration', function () {
    $event = Event::factory()->registration()->create();
    $type = ticketType($event, 'early', 2500);

    $registration = signUp($event, User::factory()->create(), 'early');
    expect($registration->ticket_price_cents)->toBe(2500);

    $type->update(['price_cents' => 3000]);

    expect($registration->fresh()->ticket_price_cents)->toBe(2500);
});

it('rejects an inactive ticket type', function () {
    $event = Event::factory()->registration()->create();
    ticketType($event, 'old', 1000, active: false);

    expect(fn () => signUp($event, User::factory()->create(), 'old'))->toThrow(RegistrationException::class);
});

it('rejects a ticket key of another event', function () {
    $event = Event::factory()->registration()->create();
    $other = Event::factory()->registration()->create();
    ticketType($other, 'vip', 5000);

    expect(fn () => signUp($event, User::factory()->create(), 'vip'))->toThrow(RegistrationException::class);
});

it('re-snapshots and clears paid_by when a cancelled registration is reactivated', function () {
    $event = Event::factory()->registration()->create();
    $type = ticketType($event, 'early', 2500);
    $user = User::factory()->create();

    $registration = signUp($event, $user, 'early');
    $registration->paid_at = now();
    $registration->paid_by = 'Max';
    $registration->save();
    app(CancelRegistration::class)->handle($registration);

    $type->update(['price_cents' => 3000]);

    $again = signUp($event, $user, 'early');

    expect($again->ticket_price_cents)->toBe(3000)
        ->and($again->paid_by)->toBeNull()
        ->and($again->paid_at)->toBeNull()
        ->and($again->fresh()->paid_by)->toBeNull();
});

it('keeps paid_by and ticket_price_cents out of mass assignment', function () {
    $registration = new EventRegistration(['paid_by' => 'x', 'ticket_price_cents' => 5]);

    expect($registration->paid_by)->toBeNull()->and($registration->ticket_price_cents)->toBeNull();
});

it('migration backfill maps legacy tickets', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['early', 'standard']]]);
    TicketType::query()->where('event_id', $event->id)->delete();
    $registration = EventRegistration::factory()->create(['event_id' => $event->id, 'ticket_type' => 'early']);

    (require database_path('migrations/2026_11_22_100000_create_ticket_types_table.php'))::backfill();

    $keys = TicketType::query()->where('event_id', $event->id)->orderBy('sort')->pluck('key')->all();

    expect($keys)->toBe(['early', 'standard'])
        ->and(TicketType::query()->where('event_id', $event->id)->sum('price_cents'))->toBe(0)
        ->and($registration->fresh()->ticket_price_cents)->toBe(0);
});

it('my registration summary carries price and ticket name', function () {
    $event = Event::factory()->registration()->create();
    ticketType($event, 'early', 2500, name: 'Early Bird');
    $user = User::factory()->create();
    signUp($event, $user, 'early');

    $summary = MyRegistration::summary($event, $user);

    expect($summary['ticketPriceCents'])->toBe(2500)
        ->and($summary['ticketName'])->toBe('Early Bird')
        ->and($summary)->not->toHaveKey('paidBy');
});

it('generates unique ticket keys from names', function () {
    $event = Event::factory()->registration()->create();

    expect(TicketType::uniqueKeyFor($event, 'Early Bird'))->toBe('early_bird');

    ticketType($event, 'early_bird', 100);

    expect(TicketType::uniqueKeyFor($event, 'Early Bird'))->toBe('early_bird_2');
});
