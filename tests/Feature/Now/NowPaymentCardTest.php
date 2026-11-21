<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Tournaments\Enums\MatchStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../WebVoice/NativeClient/PlayHelpers.php';

/** @return array{0: Event, 1: User, 2: EventRegistration} */
function payNowSetup(string $state = 'live', int $price = 2500, array $reg = [], ?Event $event = null): array
{
    $event ??= $state === 'live' ? Event::factory()->live()->create() : Event::factory()->registration()->create();
    TicketType::query()->updateOrCreate(
        ['event_id' => $event->id, 'key' => 'standard'],
        ['name' => 'Flatrate', 'price_cents' => 2500, 'sort' => 1, 'active' => true],
    );
    $user = User::factory()->create();
    $registration = EventRegistration::factory()->for($event)->for($user)->create(['ticket_type' => 'standard', 'ticket_price_cents' => $price, ...$reg]);

    return [$event, $user, $registration];
}

function payNowCards(User $user): array
{
    return test()->actingAs($user)->get('/')->assertOk()->viewData('page')['props']['forYou']['cards'];
}

it('shows the payment card for an unpaid ticket', function (string $state) {
    [$event, $user, $registration] = payNowSetup($state);
    EventPaymentSettings::forEvent($event)->forceFill(['payment_link' => 'https://paypal.me/example-orga'])->save();

    $card = collect(payNowCards($user))->firstWhere('kind', 'payment');

    expect($card)->toMatchArray([
        'id' => 'payment:'.$registration->id,
        'state' => 'open',
        'title' => 'Ticket noch nicht bezahlt',
        'body' => '25,00 € · Flatrate',
        'at' => null,
        'href' => route('events.register', $event),
    ])->and($card['data'])->toBe(['amountCents' => 2500, 'actionLabel' => 'Bezahlen'])
        ->and(json_encode($card))->not->toContain('paypal');
})->with(['upcoming', 'live']);

it('orders the payment card after a ready match and before other cards', function () {
    [$event, $user] = payNowSetup();
    playMatch($event, $user, User::factory()->create(), MatchStatus::Ready);

    $kinds = collect(payNowCards($user))->pluck('kind')->all();

    expect($kinds[0])->toBe('match')->and($kinds[1])->toBe('payment');
});

it('puts the payment card first without a ready match', function () {
    [$event, $user] = payNowSetup();
    playMatch($event, $user, User::factory()->create(), MatchStatus::Live);

    expect(payNowCards($user)[0]['kind'])->toBe('payment');
});

it('hides the payment card when paid, free, cancelled or unregistered, but shows it to an unpaid user of the same event', function () {
    [$event, $unpaid] = payNowSetup();
    [, $paid] = payNowSetup('live', 2500, ['paid_at' => now()], $event);
    [, $free] = payNowSetup('live', 0, [], $event);
    [, $cancelled] = payNowSetup('live', 2500, ['status' => RegistrationStatus::Cancelled], $event);
    $stranger = User::factory()->create();

    expect(collect(payNowCards($unpaid))->firstWhere('kind', 'payment'))->not->toBeNull();

    foreach ([$paid, $free, $cancelled, $stranger] as $user) {
        expect(collect(payNowCards($user))->firstWhere('kind', 'payment'))->toBeNull();
    }
});

it('has no payment card without a current event', function () {
    $props = $this->actingAs(User::factory()->create())->get('/')->viewData('page')['props'];

    expect($props['forYou']['cards'])->toBe([]);
});
