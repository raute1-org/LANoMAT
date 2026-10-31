<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\WebVoice\Actions\CloseChannels;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Events\ChannelsChanged;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Queries\VisibleChannels;
use App\Modules\WebVoice\Testing\FakeWebVoiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    // MatchReady also drives the Discord and Voice listeners; keep them off the network.
    fakeDiscord();
    fakeVoice();

    $this->fake = new FakeWebVoiceClient;
    $this->app->instance(WebVoiceClient::class, $this->fake);

    $this->tournament = Tournament::factory()->live()->create(['name' => 'Valorant Cup']);
    $this->ada = User::factory()->create();
    $this->bea = User::factory()->create();
    $this->cem = User::factory()->create();
    $this->dan = User::factory()->create();

    $this->alpha = TournamentEntry::factory()->team()->for($this->tournament)->create([
        'display_name' => 'Team Alpha',
        'roster_snapshot' => [['user_id' => $this->ada->id], ['user_id' => $this->bea->id]],
    ]);
    $this->bravo = TournamentEntry::factory()->team()->for($this->tournament)->create([
        'display_name' => 'Team Bravo',
        'roster_snapshot' => [['user_id' => $this->cem->id]],
    ]);

    $this->match = GameMatch::factory()->create([
        'tournament_id' => $this->tournament->id,
        'round' => 2,
        'entry1_id' => $this->alpha->id,
        'entry2_id' => $this->bravo->id,
    ]);
});

it('creates one room per team when a match becomes ready, readable only by that roster', function () {
    event(new MatchReady($this->match));

    $rooms = WebVoiceChannel::query()->where('game_match_id', $this->match->id)->orderBy('name')->get();

    expect($rooms)->toHaveCount(2)
        ->and($rooms->pluck('name')->all())->toBe(['Team Alpha', 'Team Bravo'])
        ->and($rooms[0]->kind)->toBe(ChannelKind::Match)
        ->and($rooms[0]->description)->toBe('Valorant Cup, Runde 2, gegen Team Bravo')
        ->and($rooms[0]->members->pluck('id')->sort()->values()->all())->toBe([$this->ada->id, $this->bea->id])
        ->and($rooms[1]->members->pluck('id')->all())->toBe([$this->cem->id]);

    $names = fn (User $u) => app(VisibleChannels::class)->for($u)->pluck('name')->all();

    expect($names($this->ada))->toBe(['Team Alpha'])
        ->and($names($this->cem))->toBe(['Team Bravo'])
        ->and($names($this->dan))->toBe([]);
});

it('uses the solo entrant as the whole roster of a solo entry', function () {
    $solo = TournamentEntry::factory()->solo()->for($this->tournament)->create(['display_name' => 'Solo Sam']);
    $match = GameMatch::factory()->create([
        'tournament_id' => $this->tournament->id,
        'entry1_id' => $solo->id,
        'entry2_id' => $this->bravo->id,
    ]);

    event(new MatchReady($match));

    $room = WebVoiceChannel::query()->where('name', 'Solo Sam')->firstOrFail();

    expect($room->members->pluck('id')->all())->toBe([$solo->user_id]);
});

it('is idempotent and does not resurrect an archived room', function () {
    event(new MatchReady($this->match));
    event(new MatchReady($this->match));

    expect(WebVoiceChannel::query()->count())->toBe(2);

    WebVoiceChannel::query()->update(['archived_at' => now()]);
    event(new MatchReady($this->match));

    expect(WebVoiceChannel::query()->count())->toBe(2)
        ->and(WebVoiceChannel::query()->whereNull('archived_at')->count())->toBe(0);
});

it('skips a slot that has no entrant yet', function () {
    $this->match->update(['entry2_id' => null]);

    event(new MatchReady($this->match->fresh()));

    expect(WebVoiceChannel::query()->pluck('name')->all())->toBe(['Team Alpha']);
});

it('tells open pages to reload their list when rooms appear', function () {
    Event::fake([ChannelsChanged::class]);

    event(new MatchReady($this->match));

    Event::assertDispatched(ChannelsChanged::class);
});

it('archives empty rooms at once when the match is completed', function () {
    event(new MatchReady($this->match));

    event(new MatchCompleted($this->match));

    expect(WebVoiceChannel::query()->whereNull('archived_at')->count())->toBe(0);
});

it('keeps a room that still has people in it', function () {
    event(new MatchReady($this->match));
    $alpha = WebVoiceChannel::query()->where('name', 'Team Alpha')->firstOrFail();
    $this->fake->join($alpha->roomName(), $this->ada->id);

    event(new MatchCompleted($this->match));

    expect($alpha->fresh()->isArchived())->toBeFalse()
        ->and(WebVoiceChannel::query()->where('name', 'Team Bravo')->firstOrFail()->isArchived())->toBeTrue();

    // The last person leaves: the next look archives it.
    $this->fake->rooms = [];
    expect(app(CloseChannels::class)->handle([$alpha->id]))->toBe([])
        ->and($alpha->fresh()->isArchived())->toBeTrue();
});

it('treats an unreachable server as occupied and never archives on a guess', function () {
    event(new MatchReady($this->match));
    $ids = WebVoiceChannel::query()->pluck('id')->all();
    $this->fake->unavailable = true;

    $waiting = app(CloseChannels::class)->handle($ids);

    expect($waiting)->toEqualCanonicalizing($ids)
        ->and(WebVoiceChannel::query()->whereNull('archived_at')->count())->toBe(2);
});

it('closes every leftover room of a tournament when it completes', function () {
    event(new MatchReady($this->match));
    $other = GameMatch::factory()->create([
        'tournament_id' => $this->tournament->id,
        'entry1_id' => $this->alpha->id,
        'entry2_id' => $this->bravo->id,
    ]);
    event(new MatchReady($other));
    expect(WebVoiceChannel::query()->whereNull('archived_at')->count())->toBe(4);

    event(new TournamentCompleted($this->tournament));

    expect(WebVoiceChannel::query()->whereNull('archived_at')->count())->toBe(0);
});

it('never touches general channels when a match closes', function () {
    $lobby = WebVoiceChannel::factory()->create();
    event(new MatchReady($this->match));

    event(new MatchCompleted($this->match));

    expect($lobby->fresh()->isArchived())->toBeFalse();
});

it('prunes old archived match channels with their chat, keeps general ones and recent ones', function () {
    $old = WebVoiceChannel::factory()->match()->create(['archived_at' => now()->subDays(10)]);
    $recent = WebVoiceChannel::factory()->match()->create(['archived_at' => now()->subDays(2)]);
    $oldGeneral = WebVoiceChannel::factory()->create(['archived_at' => now()->subDays(30)]);

    $this->artisan('webvoice:prune')->assertSuccessful();

    expect(WebVoiceChannel::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(WebVoiceChannel::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(WebVoiceChannel::query()->whereKey($oldGeneral->id)->exists())->toBeTrue();
});
