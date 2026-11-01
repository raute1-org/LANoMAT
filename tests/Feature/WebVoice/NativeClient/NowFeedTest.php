<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voting\Actions\CastVote;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use App\Modules\WebVoice\Contracts\OrderCardSource;
use App\Modules\WebVoice\Contracts\ScheduleCardSource;
use App\Modules\WebVoice\Contracts\VoteCardSource;
use App\Modules\WebVoice\NativeClient\Support\Now\NullOrderCardSource;
use App\Modules\WebVoice\NativeClient\Support\Now\NullScheduleCardSource;
use App\Modules\WebVoice\NativeClient\Support\Now\NullVoteCardSource;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/PlayHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeDiscord();
    fakeMumble();
    $this->event = playEvent();
    $this->me = User::factory()->create();
    $this->them = User::factory()->create();
    playRegister($this->me, $this->event);
    playRegister($this->them, $this->event);
    $this->headers = playBearer($this->me);
    $this->url = "/api/voice-client/v1/events/{$this->event->slug}/now";
});

function playNotify(User $user, string $title, int $minutesAgo = 0, string $category = 'match'): void
{
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Test',
        'data' => ['category' => $category, 'title' => $title, 'body' => 'Text'],
        'created_at' => now()->subMinutes($minutesAgo),
    ]);
}

function playOpenPoll(Event $event, array $attributes = []): Poll
{
    $poll = Poll::factory()->open()->create(['event_id' => $event->id, 'question' => 'Welche Map?', ...$attributes]);
    PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Dust', 'sort' => 1]);
    PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Mirage', 'sort' => 2]);

    return $poll;
}

it('requires a valid bearer token and a visible event', function () {
    $this->getJson($this->url)->assertUnauthorized();
    $this->getJson($this->url, ['Authorization' => 'Bearer garbage'])->assertUnauthorized();
    $this->get($this->url, $this->headers)->assertOk();

    $draft = Event::factory()->draft()->create();
    $this->getJson("/api/voice-client/v1/events/{$draft->slug}/now", $this->headers)->assertNotFound();
    $this->getJson('/api/voice-client/v1/events/nope/now', $this->headers)->assertNotFound();
});

it('does not accept the bearer token on the web', function () {
    $this->get("/events/{$this->event->slug}", $this->headers)->assertRedirect('/login');
});

it('is calm and shows the next schedule item when nothing needs attention', function () {
    ScheduleItem::factory()->create(['event_id' => $this->event->id, 'title' => 'Siegerehrung', 'starts_at' => now()->addHours(3)]);
    ScheduleItem::factory()->create(['event_id' => $this->event->id, 'title' => 'Eröffnung', 'starts_at' => now()->addHour()]);

    $response = $this->getJson($this->url, $this->headers)->assertOk();

    expect($response->json('cards'))->toHaveCount(1)
        ->and($response->json('cards.0.kind'))->toBe('schedule')
        ->and($response->json('cards.0.state'))->toBe('next')
        ->and($response->json('cards.0.data.item.title'))->toBe('Eröffnung')
        ->and($response->json('more'))->toBe([]);
});

it('builds match cards for ready, live and result confirmation', function () {
    $tournament = Tournament::factory()->live()->create(['event_id' => $this->event->id]);
    $ready = playMatch($this->event, $this->me, $this->them, MatchStatus::Ready, $tournament);

    $cards = $this->getJson($this->url, $this->headers)->assertOk()->json('cards');
    expect($cards)->toHaveCount(1)
        ->and($cards[0])->toMatchArray(['kind' => 'match', 'state' => 'ready', 'id' => 'match:'.$ready->id])
        ->and($cards[0]['data']['match']['opponent']['name'])->toBe('Die Anderen')
        ->and($cards[0]['data']['match'])->toHaveKeys(['voiceRoomId', 'server', 'can']);

    $ready->forceFill(['status' => MatchStatus::Live])->save();
    expect($this->getJson($this->url, $this->headers)->json('cards.0.state'))->toBe('live');

    // A claim by me needs nothing from me; the opponent gets a confirm card.
    $this->postJson("/api/voice-client/v1/matches/{$ready->id}/report", ['score1' => 2, 'score2' => 0], $this->headers)->assertOk();
    expect(collect($this->getJson($this->url, $this->headers)->json('cards'))->pluck('kind')->contains('match'))->toBeFalse();

    $theirs = $this->getJson($this->url, playBearer($this->them))->json('cards');
    expect($theirs[0]['state'])->toBe('confirm_result')
        ->and($theirs[0]['data']['match']['can']['confirm'])->toBeTrue();
});

it('shows a check-in card while the window is open, urgent ones first', function () {
    $tournament = Tournament::factory()->checkIn()->create([
        'event_id' => $this->event->id, 'name' => 'Cup',
        'checkin_opens_at' => now()->subMinutes(5), 'checkin_closes_at' => now()->addMinutes(5),
    ]);
    playEntry($tournament, $this->me);
    playMatch($this->event, $this->me, $this->them);

    $cards = $this->getJson($this->url, $this->headers)->json('cards');

    // Closing within ten minutes outranks a ready match.
    expect(collect($cards)->pluck('state')->all())->toBe(['checkin_open', 'ready'])
        ->and($cards[0]['data']['tournament']['can']['checkIn'])->toBeTrue()
        ->and($cards[0]['at'])->not->toBeNull();

    $tournament->forceFill(['checkin_closes_at' => now()->addHour()])->save();
    expect(collect($this->getJson($this->url, $this->headers)->json('cards'))->pluck('state')->all())->toBe(['ready', 'checkin_open']);

});

it('adds an open poll the user has not voted in, and drops it once they voted', function () {
    $poll = playOpenPoll($this->event, ['closes_at' => now()->addHour()]);
    playOpenPoll($this->event, ['question' => 'Zu', 'closes_at' => now()->subMinute()]);
    Poll::factory()->create(['event_id' => $this->event->id, 'question' => 'Entwurf']);
    playOpenPoll(Event::factory()->live()->create(), ['question' => 'Anderes Event']);

    $cards = $this->getJson($this->url, $this->headers)->json('cards');

    expect($cards)->toHaveCount(1)
        ->and($cards[0])->toMatchArray(['kind' => 'vote', 'id' => 'vote:'.$poll->id, 'state' => 'open'])
        ->and($cards[0]['data']['question'])->toBe('Welche Map?')
        ->and(collect($cards[0]['data']['options'])->pluck('label')->all())->toBe(['Dust', 'Mirage'])
        ->and($cards[0]['data']['closesAt'])->not->toBeNull();

    // This branch has no vote endpoint; vote through the same Action the web page uses.
    app(CastVote::class)->handle($poll, $this->me, $poll->options()->first());

    expect($this->getJson($this->url, $this->headers)->json('cards'))->toBe([]);
});

it('adds an order card when the ordering window closes soon and nothing is ordered yet', function () {
    $soon = FoodOrder::factory()->open()->create(['event_id' => $this->event->id, 'title' => 'Pizza', 'closes_at' => now()->addMinutes(30)]);
    FoodOrder::factory()->open()->create(['event_id' => $this->event->id, 'title' => 'Später', 'closes_at' => now()->addHours(5)]);

    $cards = $this->getJson($this->url, $this->headers)->json('cards');
    expect($cards)->toHaveCount(1)
        ->and($cards[0])->toMatchArray(['kind' => 'order', 'id' => 'order:'.$soon->id, 'state' => 'closing_soon'])
        ->and($cards[0]['data']['menu'])->not->toBeEmpty();

    // Someone without a confirmed registration is not offered to order.
    expect($this->getJson($this->url, playBearer(User::factory()->create()))->json('cards'))->toBe([]);

    FoodOrderItem::factory()->create(['food_order_id' => $soon->id, 'user_id' => $this->me->id]);
    expect($this->getJson($this->url, $this->headers)->json('cards'))->toBe([]);
});

it('orders by urgency, shows at most five and moves the rest to more', function () {
    $tournament = Tournament::factory()->live()->create(['event_id' => $this->event->id]);
    playMatch($this->event, $this->me, $this->them, MatchStatus::Ready, $tournament);
    playOpenPoll($this->event, ['closes_at' => now()->addMinutes(5)]);
    FoodOrder::factory()->open()->create(['event_id' => $this->event->id, 'closes_at' => now()->addMinutes(20)]);
    ScheduleItem::factory()->create(['event_id' => $this->event->id, 'starts_at' => now()->addHour()]);
    foreach ([30, 20, 10, 5, 1] as $i => $minutes) {
        playNotify($this->me, 'Meldung '.$i, $minutes);
    }
    // Someone else's unread notification is not mine.
    playNotify($this->them, 'Fremd');

    $response = $this->getJson($this->url, $this->headers)->assertOk();
    $cards = $response->json('cards');
    $more = $response->json('more');

    // match(10), vote closing soon(30), order(45), then notifications newest first; the schedule stays out while there is action.
    expect(collect($cards)->pluck('kind')->all())->toBe(['match', 'vote', 'order', 'notification', 'notification'])
        ->and(collect($cards)->skip(3)->pluck('title')->all())->toBe(['Meldung 4', 'Meldung 3'])
        ->and($more)->toHaveCount(1)
        ->and($more[0]['title'])->toBe('Meldung 2')
        ->and(collect([...$cards, ...$more])->pluck('kind')->contains('schedule'))->toBeFalse()
        ->and(collect([...$cards, ...$more])->where('kind', 'notification'))->toHaveCount(3);
});

it('leaves out vote, order and schedule while their modules are off', function () {
    playOpenPoll($this->event);
    FoodOrder::factory()->open()->create(['event_id' => $this->event->id, 'closes_at' => now()->addMinutes(20)]);
    ScheduleItem::factory()->create(['event_id' => $this->event->id, 'starts_at' => now()->addHour()]);
    expect($this->getJson($this->url, $this->headers)->json('cards'))->toHaveCount(2);

    // Core profile plus WebVoice: every other optional module is off.
    $this->app->instance(Modules::class, new Modules('core', ['web_voice' => true]));

    expect(app(VoteCardSource::class))->toBeInstanceOf(NullVoteCardSource::class)
        ->and(app(OrderCardSource::class))->toBeInstanceOf(NullOrderCardSource::class)
        ->and(app(ScheduleCardSource::class))->toBeInstanceOf(NullScheduleCardSource::class);

    $this->getJson($this->url, $this->headers)->assertOk()->assertJson(['cards' => [], 'more' => []]);
});

it('exposes the enabled modules in the client config without touching the existing fields', function () {
    $config = $this->getJson('/api/voice-client/v1/config', $this->headers)->assertOk()->json();

    expect($config)->toHaveKeys(['api_version', 'livekit_url', 'voice_ready', 'audio', 'modules'])
        ->and($config['modules'])->toHaveKeys(['catering', 'voting', 'files', 'game_servers', 'tournaments', 'schedule', 'lfg', 'web_voice'])
        ->and($config['modules']['tournaments'])->toBeTrue()
        ->and($config['modules']['voting'])->toBeTrue();

    $this->app->instance(Modules::class, new Modules('core', ['web_voice' => true]));
    $off = $this->getJson('/api/voice-client/v1/config', $this->headers)->json('modules');

    expect($off['voting'])->toBeFalse()->and($off['game_servers'])->toBeFalse()->and($off['tournaments'])->toBeTrue();
});
