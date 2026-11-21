<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Lfg\Actions\ToggleWantToPlay;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Notifications\Notifications\RegistrationConfirmed;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Teams\Actions\CreateTeam;
use App\Modules\Teams\Models\Team;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Reloads only the given props, as the page does for its deferred blocks. */
function nowPartial(User $user, array $props): AssertableInertia|TestResponse
{
    $version = test()->actingAs($user)->withHeaders(['X-Inertia' => 'true'])->get('/')->headers->get('X-Inertia-Version');

    return test()->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Now',
        'X-Inertia-Partial-Data' => implode(',', $props),
    ])->get('/');
}

it('still renders the guest landing at the root', function () {
    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

it('redirects /dashboard to the root and keeps it behind login', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/');
});

it('renders the none state without a current event', function () {
    $this->actingAs(User::factory()->create())->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Now')
            ->where('state', 'none')
            ->where('event', null)
            ->where('forYou', ['cards' => [], 'more' => []])
            ->where('me', null)
            ->where('food', null)
            ->where('schedule', null)
            ->where('permanent', [])
            ->where('labels.title', 'Jetzt')
        );
});

it('renders the upcoming state with availability and counts but no permanent clutter for drafts', function () {
    Event::factory()->draft()->create(['name' => 'Hidden']);
    $event = Event::factory()->registration()->create(['name' => 'Testlan', 'max_participants' => 10]);
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->paid()->create();
    EventRegistration::factory()->for($event)->cancelled()->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'upcoming')
            ->where('event.id', $event->id)
            ->where('event.name', 'Testlan')
            ->where('event.availability', 'open')
            ->where('event.freeSpots', 9)
            ->where('event.registeredCount', 1)
            ->where('event.checkedInCount', 0)
            ->where('me.registration.status', 'confirmed')
            ->missing('me.registration.qrSvg')
            ->missing('me.registration.qr_token')
            ->has('permanent')
        );
});

it('renders the live state with own seat, first team and no permanent block', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $registration = EventRegistration::factory()->for($event)->for($user)->create(['checked_in_at' => now()]);
    $seat = Seat::factory()->for($event)->create(['label' => 'B7']);
    SeatAssignment::factory()->create(['seat_id' => $seat->id, 'registration_id' => $registration->id]);
    app(CreateTeam::class)->handle($user, 'Alpha', 'ALP');
    Team::factory()->create(['name' => 'Foreign']);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'live')
            ->where('event.checkedInCount', 1)
            ->where('event.registeredCount', 1)
            ->where('me.seat', 'B7')
            ->where('me.team', 'Alpha')
            ->where('permanent', null)
        );
});

it('adds hrefs and action labels to the for-you cards', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();
    ScheduleItem::factory()->for($event)->create(['title' => 'Siegerehrung', 'starts_at' => now()->addHour()]);
    $user->notify(new RegistrationConfirmed('Testlan'));

    $this->actingAs($user)->get('/')
        ->assertInertia(function (AssertableInertia $page) use ($event) {
            $cards = collect($page->toArray()['props']['forYou']['cards']);
            $schedule = $cards->firstWhere('kind', 'schedule');
            $notification = $cards->firstWhere('kind', 'notification');

            expect($schedule['href'])->toBe(route('schedule.index', $event))
                ->and($schedule['data']['actionLabel'])->toBe('Zeitplan')
                ->and($notification['href'])->toBeNull()
                ->and($notification['data'])->not->toHaveKey('actionLabel');
        });
});

it('shows the open food round and whether the viewer may order', function () {
    $event = Event::factory()->live()->create();
    FoodOrder::factory()->for($event)->open()->create(['menu' => [new MenuOption(key: 'cola', name: 'Cola', priceCents: 200)]]);
    $stranger = User::factory()->create();
    $member = User::factory()->create();
    EventRegistration::factory()->for($event)->for($member)->create();

    $this->actingAs($stranger)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('food.open.orderable', false));
    $this->actingAs($member)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('food.open.orderable', true));
});

it('limits the upcoming schedule to the next three items', function () {
    $event = Event::factory()->live()->create();
    ScheduleItem::factory()->for($event)->create(['title' => 'Läuft', 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addMinutes(20)]);
    foreach (range(1, 4) as $i) {
        ScheduleItem::factory()->for($event)->create(['title' => "Später $i", 'starts_at' => now()->addHours($i)]);
    }

    $this->actingAs(User::factory()->create())->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('schedule.current.title', 'Läuft')
            ->has('schedule.upcoming', 3)
            ->where('schedule.upcoming.0.title', 'Später 1')
        );
});

it('defers nowPlaying and wantToPlay and resolves them on a partial reload', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $cs = Game::factory()->create(['name' => 'CS2']);
    $ut = Game::factory()->create(['name' => 'UT2004']);
    foreach ([[$cs, 2], [$ut, 7]] as [$game, $players]) {
        ServerLink::factory()->create([
            'event_id' => $event->id,
            'game_id' => $game->id,
            'status' => ServerLinkStatus::Ready,
            'join_info' => new JoinInfo(address: '198.51.100.1', port: 27015, connectString: 'steam://connect/198.51.100.1:27015'),
            'query_info' => ['name' => 'S', 'map' => 'm', 'players' => $players, 'max_players' => 16],
        ]);
    }
    LfgPost::factory()->for($event)->for(User::factory()->create())->create([
        'game' => 'Quake', 'title' => ToggleWantToPlay::titleFor('Quake'), 'body' => null, 'slots_needed' => null,
    ]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('nowPlaying')->missing('wantToPlay'));

    $props = nowPartial($user, ['nowPlaying', 'wantToPlay'])->assertOk()->json('props');

    expect(array_column($props['nowPlaying'], 'game'))->toBe(['UT2004', 'CS2'])
        ->and($props['nowPlaying'][0])->toMatchArray(['people' => 7, 'servers' => 1, 'players' => 7, 'maxPlayers' => 16])
        ->and($props['nowPlaying'][0]['join']['connectString'])->toBe('steam://connect/198.51.100.1:27015')
        ->and($props['wantToPlay']['games'][0])->toMatchArray(['game' => 'Quake', 'count' => 1, 'mine' => false])
        ->and($props['wantToPlay']['toggleUrl'])->toBe(route('lfg.want-to-play', $event));
});

it('hides optional blocks when their modules are off', function () {
    app()->instance(Modules::class, new Modules('core'));
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    FoodOrder::factory()->for($event)->open()->create();
    ScheduleItem::factory()->for($event)->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('food', null)
            ->where('schedule', null)
            ->where('permanent', null)
            ->where('news', [])
            ->where('nowPlaying', null)
            ->where('wantToPlay', null)
        );

    $props = nowPartial($user, ['nowPlaying', 'wantToPlay'])->assertOk()->json('props');
    expect($props['nowPlaying'] ?? null)->toBeNull()
        ->and($props['wantToPlay'] ?? null)->toBeNull();
});
