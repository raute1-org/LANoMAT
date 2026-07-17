<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Listeners\EnterWarmupOnServerReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Tournaments\Actions\EnterWarmup;
use App\Modules\Tournaments\Actions\GoLive;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Events\MatchWentLive;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('moves a Ready match into Warmup and stamps warmup_started_at', function () {
    $match = GameMatch::factory()->create(['status' => MatchStatus::Ready]);

    $updated = (new EnterWarmup)->handle($match);

    expect($updated->status)->toBe(MatchStatus::Warmup)
        ->and($updated->warmup_started_at)->not->toBeNull()
        ->and($match->fresh()->status)->toBe(MatchStatus::Warmup);
});

it('refuses to enter warmup from a status other than Ready', function () {
    $match = GameMatch::factory()->create(['status' => MatchStatus::Pending]);

    expect(fn () => (new EnterWarmup)->handle($match))
        ->toThrow(TournamentException::class);
});

it('lets a helper GoLive on a warmup match, dispatching MatchWentLive and a gong SceneOverride on the event channel', function () {
    Event::fake([MatchWentLive::class, SceneOverride::class]);

    $helper = User::factory()->create(['role' => Role::Helper]);
    $tournament = Tournament::factory()->create();
    $match = GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Warmup]);

    $result = (new GoLive)->handle($match, $helper);

    expect($result->status)->toBe(MatchStatus::Ready)
        ->and($match->fresh()->status)->toBe(MatchStatus::Ready);

    Event::assertDispatched(MatchWentLive::class, fn (MatchWentLive $event) => $event->match->id === $match->id);
});

it('lets an orga GoLive on a warmup match', function () {
    $orga = User::factory()->create(['role' => Role::Orga]);
    $tournament = Tournament::factory()->create();
    $match = GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Warmup]);

    $result = (new GoLive)->handle($match, $orga);

    expect($result->status)->toBe(MatchStatus::Ready);
});

it('forbids a participant from triggering GoLive', function () {
    $participant = User::factory()->create(['role' => Role::Participant]);
    $tournament = Tournament::factory()->create();
    $match = GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Warmup]);

    expect(fn () => (new GoLive)->handle($match, $participant))
        ->toThrow(AuthorizationException::class);

    expect($match->fresh()->status)->toBe(MatchStatus::Warmup);
});

it('refuses GoLive on a match that is not in Warmup', function () {
    $helper = User::factory()->create(['role' => Role::Helper]);
    $match = GameMatch::factory()->create(['status' => MatchStatus::Ready]);

    expect(fn () => (new GoLive)->handle($match, $helper))
        ->toThrow(TournamentException::class);
});

it('broadcasts a gong SceneOverride on the event channel when GongOnMatchLive reacts to MatchWentLive', function () {
    Event::fake([SceneOverride::class]);

    $tournament = Tournament::factory()->create();
    $match = GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Ready]);

    event(new MatchWentLive($match));

    Event::assertDispatched(SceneOverride::class, function (SceneOverride $dispatched) use ($tournament) {
        return $dispatched->eventId === $tournament->event_id
            && $dispatched->scene['type'] === 'gong';
    });
});

it('auto-enters warmup when a match-scoped ServerLink turns Ready', function () {
    $match = GameMatch::factory()->create(['status' => MatchStatus::Ready]);

    $link = ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.20', port: 27015),
    ]);

    (new EnterWarmupOnServerReady)->handle(new ServerLinkUpdated($link));

    expect($match->fresh()->status)->toBe(MatchStatus::Warmup);
});

it('does nothing on server-ready when the match is not currently Ready', function () {
    $match = GameMatch::factory()->create(['status' => MatchStatus::Reported]);

    $link = ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.21', port: 27015),
    ]);

    (new EnterWarmupOnServerReady)->handle(new ServerLinkUpdated($link));

    expect($match->fresh()->status)->toBe(MatchStatus::Reported);
});

it('exposes the German match_status label for the new Warmup case', function () {
    expect(MatchStatus::Warmup->label())->toBe(__('tournaments.match_status.warmup'))
        ->and(__('tournaments.match_status.warmup'))->toBe('Aufwärmen');
});
