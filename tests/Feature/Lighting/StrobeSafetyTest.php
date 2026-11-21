<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Lighting\Actions\ControlGameMode;
use App\Modules\Lighting\Actions\PlayScene;
use App\Modules\Lighting\Actions\SetLightPattern;
use App\Modules\Lighting\Actions\SetLights;
use App\Modules\Lighting\Actions\SetStrobePermission;
use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Jobs\PlayLightingSceneJob;
use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Models\LightingSetting;
use App\Modules\Lighting\Support\FixtureUpdate;
use App\Modules\Lighting\Support\LightState;
use App\Modules\Lighting\Support\ScenePlayer;
use App\Modules\Lighting\Testing\FakeLightingClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = FakeLightingClient::install();
    $this->orga = User::factory()->orga()->create();
    config(['services.lighting.enabled' => true, 'services.lighting.base_url' => 'http://bridge.test']);
});

it('is locked by default', function () {
    expect(LightingSetting::strobeAllowed())->toBeFalse();
});

it('forces strobe to 0 on every path while locked', function () {
    $client = app(LightingClient::class);
    $s = LightState::make('#ff0000', strobe: 200);

    $client->setAll($s);
    $client->setFixture(2, $s);
    $client->setBulk([new FixtureUpdate(1, $s)]);

    expect($this->fake->callsTo('setAll')[0][1]->strobe)->toBe(0)
        ->and($this->fake->callsTo('setFixture')[0][2]->strobe)->toBe(0)
        ->and($this->fake->callsTo('setBulk')[0][1][0]->state->strobe)->toBe(0)
        ->and($this->fake->callsTo('setAll')[0][1]->hex)->toBe('#ff0000');
});

it('forces strobe to 0 even when the state did not mention strobe', function () {
    app(LightingClient::class)->setAll(LightState::make('#fff'));

    expect($this->fake->callsTo('setAll')[0][1]->strobe)->toBe(0);
});

it('clears strobe before a pattern while locked', function () {
    app(SetLightPattern::class)($this->orga, '#00ff00', '#ff0000', 1.0);

    expect($this->fake->calls[0][0])->toBe('setAll')
        ->and($this->fake->calls[0][1]->strobe)->toBe(0)
        ->and($this->fake->calls[1][0])->toBe('pattern');
});

it('never strobes through manual actions while locked', function () {
    app(SetLights::class)($this->orga, null, LightState::make(strobe: 255));

    expect($this->fake->callsTo('setAll')[0][1]->strobe)->toBe(0);
});

it('strips strobe from scenes while locked', function () {
    $scene = LightingScene::query()->create([
        'name' => 'Blitz',
        'states' => ['all' => ['hex' => '#fff', 'strobe' => 200], '2' => ['strobe' => 100]],
    ]);

    app(PlayScene::class)($this->orga, $scene);

    expect($this->fake->callsTo('setAll')[0][1]->strobe)->toBe(0)
        ->and($this->fake->callsTo('setBulk')[0][1][0]->state->strobe)->toBe(0);
});

it('strips strobe from automation (job) while locked', function () {
    $scene = LightingScene::query()->create(['name' => 'Blitz', 'states' => ['all' => ['strobe' => 200]]]);

    (new PlayLightingSceneJob($scene->id))->handle(app(ScenePlayer::class));

    expect($this->fake->callsTo('setAll')[0][1]->strobe)->toBe(0);
});

it('blocks Game Mode while strobe is locked', function () {
    $result = app(ControlGameMode::class)->start($this->orga, 5, 250);

    expect($result->ok)->toBeFalse()->and($result->reachable)->toBeTrue()->and($result->message)->not->toBe('');
    expect($this->fake->callsTo('startGame'))->toBe([]);
});

it('starts Game Mode when the operator attested it is strobe free', function () {
    config(['lighting.game_mode_strobe_free' => true]);

    expect(app(ControlGameMode::class)->start($this->orga, 5, 250)->ok)->toBeTrue();
    expect($this->fake->callsTo('startGame'))->toHaveCount(1);
});

it('starts Game Mode when strobe is allowed', function () {
    config(['lighting.allow_strobe' => true]);

    expect(app(ControlGameMode::class)->start($this->orga)->ok)->toBeTrue();
});

it('lets strobe through but capped when allowed', function () {
    config(['lighting.allow_strobe' => true, 'lighting.max_strobe' => 120]);

    app(LightingClient::class)->setAll(LightState::make(strobe: 250));
    app(LightingClient::class)->setAll(LightState::make(strobe: 0));

    expect($this->fake->callsTo('setAll')[0][1]->strobe)->toBe(120)
        ->and($this->fake->callsTo('setAll')[1][1]->strobe)->toBe(0);
});

it('lets the database override beat the config default in both directions', function () {
    config(['lighting.allow_strobe' => true]);
    LightingSetting::current()->update(['strobe_allowed' => false]);
    expect(LightingSetting::strobeAllowed())->toBeFalse();

    config(['lighting.allow_strobe' => false]);
    LightingSetting::current()->update(['strobe_allowed' => true]);
    expect(LightingSetting::strobeAllowed())->toBeTrue();
});

it('lets only orga change the strobe permission', function () {
    $helper = User::factory()->helper()->create();

    expect(fn () => app(SetStrobePermission::class)($helper, true))
        ->toThrow(AuthorizationException::class);

    app(SetStrobePermission::class)($this->orga, true);
    expect(LightingSetting::strobeAllowed())->toBeTrue();
});

it('stops a running strobe and Game Mode when strobe is locked again', function () {
    app(SetStrobePermission::class)($this->orga, true);
    $this->fake->calls = [];

    app(SetStrobePermission::class)($this->orga, false);

    expect($this->fake->callsTo('setAll')[0][1]->strobe)->toBe(0)
        ->and($this->fake->callsTo('cancelGame'))->toHaveCount(1);
});

it('sends no stop commands when strobe is switched on', function () {
    app(SetStrobePermission::class)($this->orga, true);

    expect($this->fake->calls)->toBe([]);
});
