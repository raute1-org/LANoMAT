<?php

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\News\Models\NewsPost;
use App\Modules\Registration\Notifications\CheckinOpened;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Notifications\MatchReadyBell;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Models\Poll;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * Core-only instance, swapped in AFTER any module-dependent arrangement so the
 * request/notification under test runs with the optional module off.
 */
function goCoreOnly(): void
{
    app()->instance(Modules::class, new Modules('core'));
}

it('keeps the news prop present but empty on the event page when News is off', function () {
    $event = Event::factory()->live()->create();
    NewsPost::factory()->published()->create();

    $this->get("/events/{$event->slug}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('news', 1));

    goCoreOnly();

    $this->get("/events/{$event->slug}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('news', []));
});

it('omits the poll teaser from the hype data when Voting is off', function () {
    $event = Event::factory()->create(['status' => EventStatus::Announced, 'starts_at' => now()->addDays(14)]);
    Poll::factory()->create(['event_id' => $event->id, 'status' => PollStatus::Open, 'closes_at' => null]);

    $this->get("/events/{$event->slug}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('event.hype.activePoll.id', fn ($id) => $id !== null));

    goCoreOnly();

    $this->get("/events/{$event->slug}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('event.hype.registrationCount')
            ->where('event.hype.activePoll', null));
});

it('drops the Discord channel from the bell notifications when Discord is off', function () {
    $user = User::factory()->create();
    $match = GameMatch::factory()->create();
    $event = Event::factory()->create();

    expect((new CheckinOpened($event))->via($user))->toContain('database')->toHaveCount(2)
        ->and((new MatchReadyBell($match))->via($user))->toHaveCount(2);

    goCoreOnly();

    expect((new CheckinOpened($event))->via($user))->toBe(['database'])
        ->and((new MatchReadyBell($match))->via($user))->toBe(['database']);
});

it('shows a profile without friend state when Friends is off', function () {
    $viewer = User::factory()->create();
    $target = User::factory()->create();
    goCoreOnly();

    $this->actingAs($viewer)->get("/users/{$target->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('relationship', null));
});

it('renders the tournament page without voice links or labels when Voice is off', function () {
    fakeDiscord();
    fakeMumble();

    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->checkIn()->singleElim()->create();
    TournamentEntry::factory()->checkedIn()->count(8)->create(['tournament_id' => $tournament->id]);
    $tournament = app(StartTournament::class)->handle($tournament)->fresh();

    $match = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->orderBy('position')->first();
    $viewer = User::find(TournamentEntry::find($match->entry1_id)->user_id);
    $match->update(['voice_channels' => ['mumble' => ['entry1_channel_id' => 101, 'entry2_channel_id' => 102]]]);

    goCoreOnly();

    $this->actingAs($viewer)->get("/tournaments/{$tournament->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('myMatchVoiceLinks', [])
            ->where('voiceLabels', []));
});

it('projects no server DTO when GameServers is off', function () {
    $link = ServerLink::factory()->create();
    $match = GameMatch::factory()->create(['server_link_id' => $link->id]);

    expect(BracketMatchProjection::fromMatch($match->fresh())['server'])->not->toBeNull();

    goCoreOnly();

    expect(BracketMatchProjection::fromMatch($match->fresh())['server'])->toBeNull();
});
