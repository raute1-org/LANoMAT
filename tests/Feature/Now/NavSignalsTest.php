<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Support\Modules\Modules;
use App\Support\Now\NavSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

function signalsFor(User $user): array
{
    return app(NavSignals::class)->for($user);
}

it('is all null without a current event and no servers', function () {
    expect(signalsFor(User::factory()->create()))->toBe([
        'serversOnline' => 0,
        'tournament' => null,
        'foodClosesAt' => null,
        'seat' => null,
        'present' => null,
        'registered' => null,
    ]);
});

it('counts players online, own seat and checked-in people of the current event', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $registration = EventRegistration::factory()->for($event)->for($user)->create(['checked_in_at' => now()]);
    EventRegistration::factory()->for($event)->create();
    EventRegistration::factory()->for($event)->cancelled()->create(['checked_in_at' => now()]);
    $seat = Seat::factory()->for($event)->create(['label' => 'C3']);
    SeatAssignment::factory()->create(['seat_id' => $seat->id, 'registration_id' => $registration->id]);
    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => Game::factory()->create(['name' => 'CS2'])->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '198.51.100.1', port: 27015, connectString: 'steam://connect/198.51.100.1:27015'),
        'query_info' => ['name' => 'S', 'map' => 'm', 'players' => 5, 'max_players' => 16],
    ]);

    expect(signalsFor($user))->toMatchArray(['serversOnline' => 5, 'seat' => 'C3', 'present' => 1]);
});

it('reports the closing time of the open food round only until the viewer has ordered', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();
    $order = FoodOrder::factory()->for($event)->open()->create(['menu' => [new MenuOption(key: 'cola', name: 'Cola', priceCents: 200)]]);

    expect(signalsFor($user)['foodClosesAt'])->toBe($order->closes_at->toIso8601String());

    Cache::flush();
    FoodOrderItem::factory()->for($order, 'foodOrder')->for($user)->create(['selection' => ['option_key' => 'cola'], 'price_cents' => 200]);

    expect(signalsFor($user)['foodClosesAt'])->toBeNull();
});

it('is null for the fields of modules that are off', function () {
    app()->instance(Modules::class, new Modules('core'));
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    FoodOrder::factory()->for($event)->open()->create();

    expect(signalsFor($user))->toMatchArray(['serversOnline' => null, 'tournament' => null, 'foodClosesAt' => null]);
});

it('caches the bundle per user for thirty seconds', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $other = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create(['checked_in_at' => now()]);

    expect(signalsFor($user)['present'])->toBe(1);

    EventRegistration::factory()->for($event)->create(['checked_in_at' => now()]);

    expect(signalsFor($user)['present'])->toBe(1)
        ->and(signalsFor($other)['present'])->toBe(2);

    $this->travel(NavSignals::TTL + 1)->seconds();

    expect(signalsFor($user)['present'])->toBe(2);
});

it('shares navSignals for logged-in users', function () {
    $this->actingAs(User::factory()->create())->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('navSignals.serversOnline'));
});

it('shares null navSignals with guests', function () {
    $this->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('navSignals', null));
});

it('reports whether the viewer has a non-cancelled registration for the current event', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $cancelled = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();
    EventRegistration::factory()->for($event)->for($cancelled)->cancelled()->create();

    expect(signalsFor($user)['registered'])->toBeTrue()
        ->and(signalsFor($cancelled)['registered'])->toBeFalse()
        ->and(signalsFor(User::factory()->create())['registered'])->toBeFalse();
});
