<?php

use App\Models\User;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Lfg\Actions\ToggleWantToPlay;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Lfg\Support\WantToPlayBoard;
use App\Modules\Notifications\Notifications\RegistrationConfirmed;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Support\LocationSnapshot;
use App\Modules\Presence\Support\LocationSnapshotStore;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Teams\Actions\CreateTeam;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Actions\SubmitMatchReport;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

require_once __DIR__.'/../WebVoice/NativeClient/PlayHelpers.php';

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
    $this->actingAs(User::factory()->create())->post('/dashboard')->assertStatus(405);
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

it('rescues a failing deferred block instead of failing the partial reload', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    app()->bind(WantToPlayBoard::class, fn () => throw new RuntimeException('board down'));

    $response = nowPartial($user, ['wantToPlay'])->assertOk();

    expect($response->json('rescuedProps'))->toContain('wantToPlay')
        ->and($response->json('props'))->not->toHaveKey('wantToPlay');
});

it('counts max players of ready servers only, like players', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    $cs = Game::factory()->create(['name' => 'CS2']);
    foreach ([ServerLinkStatus::Ready, ServerLinkStatus::NotResponding] as $status) {
        ServerLink::factory()->create([
            'event_id' => $event->id,
            'game_id' => $cs->id,
            'status' => $status,
            'join_info' => new JoinInfo(address: '198.51.100.1', port: 27015, connectString: 'steam://connect/198.51.100.1:27015'),
            'query_info' => ['name' => 'S', 'map' => 'm', 'players' => 3, 'max_players' => 16],
        ]);
    }

    $props = nowPartial($user, ['nowPlaying'])->assertOk()->json('props');

    expect($props['nowPlaying'][0])->toMatchArray(['servers' => 2, 'players' => 3, 'maxPlayers' => 16]);
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

/** @return array<string, mixed> the props of the first page load for $user */
function nowProps(User $user): array
{
    return test()->actingAs($user)->get('/')->assertOk()->viewData('page')['props'];
}

/** Fills the presence snapshot: one registered, visible person per entry of $games (a game name each). */
function nowLocated(Event $event, array $games): User
{
    $viewer = User::factory()->create();
    playRegister($viewer, $event);
    $people = [];

    foreach ($games as $index => $game) {
        $user = $index === 0 ? $viewer : User::factory()->create();

        if ($index !== 0) {
            playRegister($user, $event);
        }

        $people[$user->id] = new PersonLocation($user->id, [], new GameLocation(GameLocation::SOURCE_STEAM, $game, null, null, null, null, now()->toIso8601String()));
    }

    app(LocationSnapshotStore::class)->put(new LocationSnapshot(now()->toIso8601String(), $people));

    return $viewer;
}

function nowServer(Event $event, string $game, int $players): void
{
    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => Game::firstOrCreate(['name' => $game], Game::factory()->raw(['name' => $game]))->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '198.51.100.1', port: 27015, connectString: 'steam://connect/198.51.100.1:27015'),
        'query_info' => ['name' => 'S', 'map' => 'm', 'players' => $players, 'max_players' => 16],
    ]);
}

it('merges servers and located people per game: people is the larger, located-only rows have no server', function () {
    $event = Event::factory()->live()->create();
    nowServer($event, 'CS2', 3);
    nowServer($event, 'UT2004', 7);
    // Five people on "cs2" (merges case-insensitively into the CS2 server row), one on UT2004
    // (fewer than its 7 players), two on Quake and two on Anno (located only).
    $viewer = nowLocated($event, ['cs2', 'cs2', 'cs2', 'cs2', 'cs2', 'UT2004', 'Quake', 'Quake', 'Anno', 'Anno']);

    $rows = nowPartial($viewer, ['nowPlaying'])->assertOk()->json('props.nowPlaying');

    expect(array_column($rows, 'game'))->toBe(['UT2004', 'CS2', 'Anno', 'Quake'])
        ->and($rows[0])->toMatchArray(['people' => 7, 'servers' => 1, 'players' => 7])
        ->and($rows[1])->toMatchArray(['game' => 'CS2', 'people' => 5, 'servers' => 1, 'players' => 3, 'maxPlayers' => 16])
        ->and($rows[1]['join'])->not->toBeNull()
        ->and($rows[2])->toMatchArray(['people' => 2, 'servers' => 0, 'players' => 0, 'maxPlayers' => 0, 'join' => null])
        ->and($rows[3])->toMatchArray(['people' => 2, 'servers' => 0, 'join' => null]);
});

it('caps nowPlaying at eight rows', function () {
    $event = Event::factory()->live()->create();
    $viewer = nowLocated($event, array_map(fn (int $i): string => sprintf('Game %02d', $i), range(1, 10)));

    $rows = nowPartial($viewer, ['nowPlaying'])->assertOk()->json('props.nowPlaying');

    expect($rows)->toHaveCount(8)
        ->and($rows[0]['game'])->toBe('Game 01')
        ->and($rows[7]['game'])->toBe('Game 08');
});

it('only offers nowPlaying while the event is live', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    nowServer($event, 'CS2', 3);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'upcoming')->where('nowPlaying', null));
    expect(nowPartial($user, ['nowPlaying'])->json('props.nowPlaying') ?? null)->toBeNull();
});

it('hides the servers of a game from people who may not view them but keeps located counts', function () {
    $event = Event::factory()->live()->create();
    nowServer($event, 'CS2', 3);
    $viewer = nowLocated($event, ['Quake', 'Quake']);

    Gate::policy(ServerLink::class, (new class
    {
        public function viewPermanent(User $user): bool
        {
            return false;
        }
    })::class);

    $rows = nowPartial($viewer, ['nowPlaying'])->assertOk()->json('props.nowPlaying');

    expect(array_column($rows, 'game'))->toBe(['Quake'])
        ->and($rows[0])->toMatchArray(['people' => 2, 'servers' => 0, 'join' => null]);
});

it('reports whether the viewer has linked Steam', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    expect(nowProps($user)['me']['steamLinked'])->toBeFalse();

    LinkedAccount::factory()->create(['user_id' => $user->id, 'provider' => LinkedAccountProvider::Steam]);

    expect(nowProps($user)['me']['steamLinked'])->toBeTrue();
});

it('links a match card to its tournament with the label of each match state', function (string $state) {
    $event = Event::factory()->live()->create();
    $me = User::factory()->create();
    $them = User::factory()->create();
    playRegister($me, $event);
    $tournament = Tournament::factory()->live()->create(['event_id' => $event->id]);

    if ($state === 'checkin_open') {
        $tournament = Tournament::factory()->checkIn()->create([
            'event_id' => $event->id,
            'checkin_opens_at' => now()->subMinutes(5), 'checkin_closes_at' => now()->addMinutes(30),
        ]);
        playEntry($tournament, $me);
        $expected = ['card_action_match_checkin'];
    } else {
        playMatch($event, $me, $them, $state === 'ready' ? MatchStatus::Ready : MatchStatus::Live, $tournament);
        $expected = [$state === 'ready' ? 'card_action_match' : 'card_action_match_report'];
    }

    $card = collect(nowProps($me)['forYou']['cards'])->firstWhere('kind', 'match');

    expect($card['state'])->toBe($state)
        ->and($card['href'])->toBe(route('tournaments.show', $tournament->id))
        ->and($card['data']['actionLabel'])->toBe(trans('now.'.$expected[0]));
})->with(['ready', 'live', 'checkin_open']);

it('links a result-confirmation card to its tournament with the report label', function () {
    $event = Event::factory()->live()->create();
    $me = User::factory()->create();
    $them = User::factory()->create();
    playRegister($me, $event);
    $match = playMatch($event, $me, $them, MatchStatus::Live);
    app(SubmitMatchReport::class)->handle($match, $match->entry2, 2, 0);

    $card = collect(nowProps($me)['forYou']['cards'])->firstWhere('kind', 'match');

    expect($card['state'])->toBe('confirm_result')
        ->and($card['href'])->toBe(route('tournaments.show', $match->tournament_id))
        ->and($card['data']['actionLabel'])->toBe(trans('now.card_action_match_report'));
});

it('links vote and order cards to the poll and the catering page', function () {
    $event = Event::factory()->live()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();
    $poll = Poll::factory()->open()->create(['event_id' => $event->id, 'question' => 'Welche Map?']);
    PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Dust', 'sort' => 1]);
    PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Mirage', 'sort' => 2]);
    FoodOrder::factory()->for($event)->open()->create(['closes_at' => now()->addMinutes(20), 'menu' => [new MenuOption(key: 'cola', name: 'Cola', priceCents: 200)]]);

    $cards = collect(nowProps($user)['forYou']['cards']);

    expect($cards->firstWhere('kind', 'vote')['href'])->toBe(route('polls.show', $poll->id))
        ->and($cards->firstWhere('kind', 'vote')['data']['actionLabel'])->toBe(trans('now.card_action_vote'))
        ->and($cards->firstWhere('kind', 'order')['href'])->toBe(route('catering.show', $event))
        ->and($cards->firstWhere('kind', 'order')['data']['actionLabel'])->toBe(trans('now.card_action_order'));
});

it('hides the permanent servers from people who may not view them', function () {
    Event::factory()->registration()->create();
    ServerLink::factory()->permanent()->create(['status' => ServerLinkStatus::Ready]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('permanent', 1));

    Gate::policy(ServerLink::class, (new class
    {
        public function viewPermanent(User $user): bool
        {
            return false;
        }
    })::class);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('permanent', null));
});
