<?php

use App\Models\User;
use App\Modules\Games\Contracts\EggCatalog;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Filament\Resources\Games\Pages\CreateGame;
use App\Modules\Games\Filament\Resources\Games\Pages\EditGame;
use App\Modules\Games\Models\Game;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.pelican.max_ram_mb' => 4096]);
    $this->actingAs(User::factory()->orga()->create());
});

function bindEggCatalog(?array $options): void
{
    app()->instance(EggCatalog::class, new class($options) implements EggCatalog
    {
        public function __construct(private readonly ?array $options) {}

        public function options(): ?array
        {
            return $this->options;
        }
    });
}

function presetRow(array $override = []): array
{
    return array_merge([
        'key' => 'ttt',
        'name' => 'TTT',
        'description' => 'Round-based',
        'enabled' => true,
        'egg_uuid' => 'uuid-1',
        'egg_name' => 'Garrys Mod (LANoMAT)',
        'requires' => [],
        'max_players' => 16,
        'map' => 'gm_construct',
        'memory_mb' => 3072,
        'disk_mb' => 20480,
        'cpu_percent' => 200,
        'environment' => [
            ['key' => 'GAMEMODE', 'value' => 'terrortown', 'overridable' => false],
            ['key' => 'SRCDS_MAP', 'value' => 'gm_construct', 'overridable' => true],
        ],
        'extra_ports' => [],
        'readiness_type' => 'a2s',
        'readiness_game' => 'Trouble in Terrorist Town',
        'readiness_folder' => 'garrysmod',
        'readiness_timeout' => 180,
        'connect_template' => 'steam://connect/{ip}:{port}',
        'content_type' => 'none',
        'content_ref' => null,
        'install_marker' => 'garrysmod',
    ], $override);
}

it('stores a full preset with environment, override flags and limits through the form', function () {
    bindEggCatalog(['uuid-1' => 'Garrys Mod (LANoMAT)']);
    $game = Game::factory()->create();

    Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
        ->fillForm(['server_presets' => [presetRow()]])
        ->call('save')
        ->assertHasNoFormErrors();

    $preset = $game->fresh()->server_presets[0];

    expect($preset->key)->toBe('ttt')
        ->and($preset->eggUuid)->toBe('uuid-1')
        ->and($preset->enabled)->toBeTrue()
        ->and($preset->config->environment)->toBe(['GAMEMODE' => 'terrortown', 'SRCDS_MAP' => 'gm_construct'])
        ->and($preset->config->overridable)->toBe(['SRCDS_MAP'])
        ->and($preset->config->memoryMb)->toBe(3072)
        ->and($preset->config->diskMb)->toBe(20480)
        ->and($preset->config->cpuPercent)->toBe(200)
        ->and($preset->config->usesA2sReadiness())->toBeTrue()
        ->and($preset->config->readinessTimeout)->toBe(180)
        ->and($preset->config->connectTemplate)->toBe('steam://connect/{ip}:{port}')
        ->and($preset->config->installMarker)->toBe('garrysmod');
});

it('loads a stored preset back into the repeater rows', function () {
    bindEggCatalog(['uuid-1' => 'Garrys Mod (LANoMAT)']);
    $game = Game::factory()->create();
    $game->server_presets = [new ServerPreset(
        'ttt', 'TTT',
        new ServerConfig(maxPlayers: 16, memoryMb: 3072, environment: ['A' => '1', 'B' => '2'], overridable: ['B'], readinessType: 'a2s'),
        eggUuid: 'uuid-1', requires: ['gslt'],
    )];
    $game->save();

    $component = Livewire::test(EditGame::class, ['record' => $game->getRouteKey()]);
    $row = array_values($component->get('data.server_presets'))[0];

    expect($row['key'])->toBe('ttt')
        ->and($row['requires'])->toBe(['gslt'])
        ->and((int) $row['memory_mb'])->toBe(3072)
        ->and(array_values($row['environment']))->toBe([
            ['key' => 'A', 'value' => '1', 'overridable' => false],
            ['key' => 'B', 'value' => '2', 'overridable' => true],
        ]);
});

it('refuses a preset whose RAM exceeds the per-server cap with the guardrail message', function () {
    bindEggCatalog(null);
    $game = Game::factory()->create();

    Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
        ->fillForm(['server_presets' => [presetRow(['memory_mb' => 8192])]])
        ->call('save')
        ->assertHasFormErrors(['server_presets.*.memory_mb']);

    expect($game->fresh()->server_presets)->toBe([]);
});

it('offers the panel eggs as a select and falls back to a text input when the panel is unreachable', function () {
    $game = Game::factory()->create();
    $game->server_presets = [new ServerPreset('ttt', 'TTT', new ServerConfig, eggUuid: 'uuid-1')];
    $game->save();

    bindEggCatalog(['uuid-1' => 'Garrys Mod (LANoMAT)']);
    $component = Livewire::test(EditGame::class, ['record' => $game->getRouteKey()]);
    $rowKey = array_key_first($component->get('data.server_presets'));
    $component->assertFormFieldExists("server_presets.{$rowKey}.egg_uuid", fn ($field) => $field instanceof Select);

    bindEggCatalog(null);
    $component = Livewire::test(EditGame::class, ['record' => $game->getRouteKey()]);
    $rowKey = array_key_first($component->get('data.server_presets'));
    $component->assertFormFieldExists("server_presets.{$rowKey}.egg_uuid_manual", fn ($field) => $field instanceof TextInput)
        ->assertFormFieldHidden("server_presets.{$rowKey}.egg_uuid");
});

it('saves a hand-typed egg uuid while the panel is unreachable', function () {
    bindEggCatalog(null);
    $game = Game::factory()->create();

    Livewire::test(EditGame::class, ['record' => $game->getRouteKey()])
        ->fillForm(['server_presets' => [presetRow(['egg_uuid' => null, 'egg_uuid_manual' => 'typed-uuid'])]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($game->fresh()->server_presets[0]->eggUuid)->toBe('typed-uuid');
});

it('creates a game with presets through the create page too', function () {
    bindEggCatalog(null);

    Livewire::test(CreateGame::class)
        ->fillForm(['name' => 'Garrys Mod', 'slug' => 'gmod', 'min_team_size' => 1, 'max_team_size' => 1, 'server_presets' => [presetRow()]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Game::query()->where('slug', 'gmod')->firstOrFail()->server_presets[0]->key)->toBe('ttt');
});
