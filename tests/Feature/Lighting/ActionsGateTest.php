<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\Lighting\Actions\BlackoutLights;
use App\Modules\Lighting\Actions\ControlGameMode;
use App\Modules\Lighting\Actions\PlayScene;
use App\Modules\Lighting\Actions\SetLightPattern;
use App\Modules\Lighting\Actions\SetLights;
use App\Modules\Lighting\Exceptions\LightingRateLimited;
use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Support\LightState;
use App\Modules\Lighting\Testing\FakeLightingClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fake = FakeLightingClient::install();
});

/** @return array<string, array{0: Closure(User): mixed}> */
function lightingActions(): array
{
    return [
        'set lights' => [fn (User $u) => app(SetLights::class)($u, null, LightState::make('#fff'))],
        'pattern' => [fn (User $u) => app(SetLightPattern::class)($u, '#fff', '#000')],
        'blackout' => [fn (User $u) => app(BlackoutLights::class)($u)],
        'game start' => [fn (User $u) => app(ControlGameMode::class)->start($u)],
        'game cancel' => [fn (User $u) => app(ControlGameMode::class)->cancel($u)],
        'scene' => [fn (User $u) => app(PlayScene::class)($u, LightingScene::query()->firstOrFail())],
    ];
}

it('lets orga and admin through the gate', function (Role $role) {
    expect(Gate::forUser(User::factory()->create(['role' => $role]))->allows('lighting.control'))->toBeTrue();
})->with([[Role::Orga], [Role::Admin]]);

it('refuses helper and participant', function (Role $role) {
    expect(Gate::forUser(User::factory()->create(['role' => $role]))->denies('lighting.control'))->toBeTrue();
})->with([[Role::Helper], [Role::Participant]]);

it('refuses every action for non-orga and never reaches the bridge', function (Closure $invoke, Role $role) {
    expect(fn () => $invoke(User::factory()->create(['role' => $role])))->toThrow(AuthorizationException::class);
    expect($this->fake->calls)->toBe([]);
})->with(fn () => collect(lightingActions())->flatMap(fn ($v, $k) => [
    "$k helper" => [$v[0], Role::Helper],
    "$k participant" => [$v[0], Role::Participant],
])->all());

it('rate limits manual actions per user', function () {
    config(['lighting.rate_limit_per_minute' => 3]);
    $orga = User::factory()->orga()->create();
    $other = User::factory()->orga()->create();

    for ($i = 0; $i < 3; $i++) {
        app(BlackoutLights::class)($orga);
    }

    expect(fn () => app(BlackoutLights::class)($orga))->toThrow(LightingRateLimited::class);
    // Another user has their own budget.
    expect(app(BlackoutLights::class)($other)->ok)->toBeTrue();
    expect($this->fake->callsTo('blackout'))->toHaveCount(4);
});

it('validates fixture ids and clamps game mode parameters', function () {
    $orga = User::factory()->orga()->create();

    expect(fn () => app(SetLights::class)($orga, 5, LightState::make('#fff')))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(SetLightPattern::class)($orga, 'nope', '#fff'))->toThrow(InvalidArgumentException::class);

    config(['lighting.game_mode_strobe_free' => true]);
    app(ControlGameMode::class)->start($orga, 99999, 1);
    expect($this->fake->callsTo('startGame')[0])->toBe(['startGame', 600, 50, null]);
});

it('reports an unreachable bridge as a result, not an exception', function () {
    $this->fake->unreachable = true;

    $result = app(BlackoutLights::class)(User::factory()->orga()->create());

    expect($result->ok)->toBeFalse()->and($result->reachable)->toBeFalse();
});
