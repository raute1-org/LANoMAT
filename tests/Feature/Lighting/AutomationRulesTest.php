<?php

declare(strict_types=1);

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event;
use App\Modules\Lighting\Enums\LightingTrigger;
use App\Modules\Lighting\Jobs\PlayLightingSceneJob;
use App\Modules\Lighting\Models\LightingRule;
use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Support\ScenePlayer;
use App\Modules\Lighting\Testing\FakeLightingClient;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event as EventBus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.lighting.enabled' => true, 'services.lighting.base_url' => 'http://bridge.test']);
    $this->scene = LightingScene::query()->where('key', 'party')->firstOrFail();
});

function rule(LightingTrigger $t, LightingScene $scene, array $extra = []): LightingRule
{
    return LightingRule::query()->create(['trigger' => $t, 'lighting_scene_id' => $scene->id, 'active' => true, 'cooldown_seconds' => 60, ...$extra]);
}

it('is registered for the tournament and event events', function () {
    foreach ([TournamentStarted::class, TournamentCompleted::class, MatchCompleted::class, EventStatusChanged::class] as $e) {
        expect(EventBus::hasListeners($e))->toBeTrue();
    }
});

it('queues the rule scene when its event fires', function () {
    Bus::fake();
    $tournament = Tournament::factory()->live()->create();
    rule(LightingTrigger::TournamentStarted, $this->scene);

    event(new TournamentStarted($tournament));

    Bus::assertDispatched(PlayLightingSceneJob::class, fn ($j) => $j->sceneId === $this->scene->id);
});

it('creates rules that are off by default', function () {
    $r = LightingRule::query()->create(['trigger' => LightingTrigger::MatchReady, 'lighting_scene_id' => $this->scene->id]);

    expect($r->fresh()->active)->toBeFalse()->and($r->fresh()->cooldown_seconds)->toBe(30);
});

it('ignores inactive rules and other triggers', function () {
    Bus::fake();
    $tournament = Tournament::factory()->live()->create();
    rule(LightingTrigger::TournamentStarted, $this->scene, ['active' => false]);
    rule(LightingTrigger::TournamentCompleted, $this->scene);

    event(new TournamentStarted($tournament));

    Bus::assertNotDispatched(PlayLightingSceneJob::class);
});

it('honours the cooldown', function () {
    Bus::fake();
    $tournament = Tournament::factory()->live()->create();
    $r = rule(LightingTrigger::TournamentStarted, $this->scene, ['cooldown_seconds' => 60]);

    event(new TournamentStarted($tournament));
    event(new TournamentStarted($tournament));
    Bus::assertDispatchedTimes(PlayLightingSceneJob::class, 1);

    $this->travel(61)->seconds();
    event(new TournamentStarted($tournament));
    Bus::assertDispatchedTimes(PlayLightingSceneJob::class, 2);
    expect($r->fresh()->last_fired_at)->not->toBeNull();
});

it('scopes rules to a tournament or event', function () {
    Bus::fake();
    $a = Tournament::factory()->live()->create();
    $b = Tournament::factory()->live()->create();
    rule(LightingTrigger::TournamentStarted, $this->scene, ['tournament_id' => $a->id]);

    event(new TournamentStarted($b));
    Bus::assertNotDispatched(PlayLightingSceneJob::class);

    event(new TournamentStarted($a));
    Bus::assertDispatchedTimes(PlayLightingSceneJob::class, 1);
});

it('reacts to match completion and event status changes', function () {
    Bus::fake();
    $match = GameMatch::factory()->create();
    $ev = Event::factory()->create();
    rule(LightingTrigger::MatchCompleted, $this->scene);
    rule(LightingTrigger::EventLive, $this->scene);

    event(new MatchCompleted($match));
    event(new EventStatusChanged($ev, EventStatus::Registration, EventStatus::Live));
    event(new EventStatusChanged($ev, EventStatus::Live, EventStatus::Finished));

    Bus::assertDispatchedTimes(PlayLightingSceneJob::class, 2);
});

it('does nothing when the master switch is off', function () {
    Bus::fake();
    config(['services.lighting.enabled' => false]);
    rule(LightingTrigger::TournamentStarted, $this->scene);

    event(new TournamentStarted(Tournament::factory()->live()->create()));

    Bus::assertNotDispatched(PlayLightingSceneJob::class);
});

it('does not play in the job when switched off meanwhile', function () {
    $fake = FakeLightingClient::install();
    config(['services.lighting.enabled' => false]);

    (new PlayLightingSceneJob($this->scene->id))->handle(app(ScenePlayer::class));

    expect($fake->calls)->toBe([]);
});

it('plays the scene through the client and swallows bridge failures', function () {
    $fake = FakeLightingClient::install();
    $fake->unreachable = true;

    (new PlayLightingSceneJob($this->scene->id))->handle(app(ScenePlayer::class));

    expect($fake->callsTo('pattern'))->not->toBeEmpty();
});

it('runs the job on the queue without retries', function () {
    expect((new PlayLightingSceneJob(1))->tries)->toBe(1)
        ->and(new PlayLightingSceneJob(1))->toBeInstanceOf(ShouldQueue::class);
});

it('skips scene states for lights the bridge does not report', function () {
    $fake = FakeLightingClient::install()->withTopology(2, 4);
    $scene = LightingScene::query()->create(['name' => 'X', 'states' => ['1' => ['hex' => '#f00'], '2' => ['hex' => '#0f0'], '3' => ['hex' => '#00f'], '4' => ['hex' => '#fff']]]);

    app(ScenePlayer::class)->play($scene);

    $ids = array_map(fn ($u) => $u->id, $fake->callsTo('setBulk')[0][1]);
    expect($ids)->toBe([1, 2]);
});
