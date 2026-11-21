<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Lighting\Filament\Pages\LightControl;
use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Models\LightingSetting;
use App\Modules\Lighting\Support\Fixture;
use App\Modules\Lighting\Support\FixtureReport;
use App\Modules\Lighting\Support\GameStatus;
use App\Modules\Lighting\Support\LightState;
use App\Modules\Lighting\Testing\FakeLightingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function lightingFake(int $fixtures = 4, ?int $channels = 6): FakeLightingClient
{
    $fake = FakeLightingClient::install()->withTopology($fixtures, $channels);
    $fake->report = new FixtureReport(true, array_map(
        fn (int $i) => new Fixture($i, LightState::make('#ff5520', 0.8, strobe: 0)),
        range(1, $fixtures),
    ), new DateTimeImmutable);

    return $fake;
}

it('forbids participants and helpers', function () {
    lightingFake();

    $this->actingAs(User::factory()->create())->get('/admin/licht')->assertForbidden();
    $this->actingAs(User::factory()->helper()->create())->get('/admin/licht')->assertForbidden();
});

it('shows orga exactly the reported lights', function () {
    lightingFake(2, 4);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/licht')
        ->assertOk()
        ->assertSee('Strahler 2')
        ->assertDontSee('Strahler 3')
        ->assertSee('#ff5520');
});

it('hides brightness, white and strobe controls on four-channel lights', function () {
    lightingFake(2, 4);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/licht')
        ->assertOk()
        ->assertDontSee('lc-brightness', false)
        ->assertDontSee('lc-temp', false)
        ->assertDontSee('lc-strobe', false)
        ->assertSee('keine Blitzsteuerung');
});

it('shows all controls on six-channel lights, strobe locked', function () {
    lightingFake(4, 6);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/licht')
        ->assertOk()
        ->assertSee('lc-brightness', false)
        ->assertSee('lc-temp', false)
        ->assertSee('Blitz ist gesperrt')
        ->assertDontSee('lc-strobe', false);
});

it('shows only colour when the channel plan is unknown', function () {
    lightingFake(4, null);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/licht')
        ->assertDontSee('lc-brightness', false);
});

it('renders the unreachable state without an error', function () {
    config(['services.lighting.enabled' => true, 'services.lighting.base_url' => 'http://bridge.test']);
    FakeLightingClient::install()->unreachable = true;

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/licht')
        ->assertOk()
        ->assertSee('Lichtserver nicht erreichbar');
});

it('applies colour, pattern, blackout and scenes through the actions', function () {
    $fake = lightingFake();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(LightControl::class)
        ->call('applyColor', '2', '#FF0000', 0.5)
        ->call('applyColor', 'all', '#00ff00', null)
        ->call('applyPattern', '#00ff00', '#ff0000', 1)
        ->call('blackout')
        ->call('playScene', LightingScene::query()->where('key', 'party')->firstOrFail()->id);

    expect($fake->callsTo('setFixture')[0][1])->toBe(2)
        ->and($fake->callsTo('setFixture')[0][2]->hex)->toBe('#ff0000')
        ->and($fake->callsTo('setAll')[0][1]->hex)->toBe('#00ff00')
        ->and($fake->callsTo('pattern'))->not->toBeEmpty()
        ->and($fake->callsTo('blackout'))->toHaveCount(1);
});

it('rejects bad input with a notification and no bridge call', function () {
    $fake = lightingFake(2, 6);
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(LightControl::class)
        ->call('applyColor', '3', '#ff0000', 1)
        ->call('applyColor', 'all', 'javascript:alert(1)', 1)
        ->call('applyColor', 'all', '#ff0000', 'abc')
        ->call('playScene', 'x');

    expect($fake->calls)->toBe([]);
});

it('does not let a helper drive the Livewire actions', function () {
    $fake = lightingFake();
    $this->actingAs(User::factory()->helper()->create());

    expect(fn () => Livewire::test(LightControl::class)->call('blackout'))->toThrow(Exception::class);
    expect($fake->calls)->toBe([]);
});

it('toggles the strobe permission for orga', function () {
    lightingFake();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(LightControl::class)->call('toggleStrobePermission');

    expect(LightingSetting::strobeAllowed())->toBeTrue();
});

it('shows a rate limit message instead of failing', function () {
    $fake = lightingFake();
    config(['lighting.rate_limit_per_minute' => 1]);
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(LightControl::class)->call('blackout')->call('blackout')->assertNotified();

    expect($fake->callsTo('blackout'))->toHaveCount(1);
});

it('shows the game status and passes a chosen mode', function () {
    config(['lighting.game_mode_strobe_free' => true]);
    $fake = lightingFake();
    $fake->game = new GameStatus(true, true, 'police', ['game', 'police']);
    $this->actingAs(User::factory()->orga()->create());

    $this->get('/admin/licht')->assertOk()->assertSee('Läuft: police')->assertSee('<option value="game">game</option>', false);

    Livewire::test(LightControl::class)->call('startGame', 'police')->call('startGame', '../x');

    expect($fake->callsTo('startGame'))->toHaveCount(1)->and($fake->callsTo('startGame')[0][3])->toBe('police');
});
