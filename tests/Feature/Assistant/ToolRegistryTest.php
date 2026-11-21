<?php

use App\Models\User;
use App\Modules\Assistant\Support\ToolRegistry;
use App\Modules\Assistant\Tools\Read\ServersListTool;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function toolNames(User $user): array
{
    return array_map(fn ($tool) => $tool->name(), ToolRegistry::forUser($user));
}

it('gives a participant and a helper no tools', function () {
    expect(ToolRegistry::forUser(User::factory()->create()))->toBe([])
        ->and(ToolRegistry::forUser(User::factory()->helper()->create()))->toBe([]);
});

it('gives an orga the tools of the enabled modules', function () {
    app()->instance(Modules::class, new Modules('full'));

    expect(toolNames(User::factory()->orga()->create()))->toContain('events_list', 'tournament_get', 'servers_list', 'server_logs');
});

it('hides the server tools while GameServers is off', function () {
    app()->instance(Modules::class, new Modules('core'));

    $names = toolNames(User::factory()->orga()->create());

    expect($names)->toContain('events_list', 'tournaments_list')
        ->and($names)->not->toContain(ServersListTool::toolName(), 'server_settings_get', 'games_list');
});

it('gives every tool a unique snake_case name', function () {
    $names = array_map(fn (string $class) => $class::toolName(), ToolRegistry::classes());

    expect($names)->toBe(array_values(array_unique($names)))
        ->and(collect($names)->every(fn ($n) => preg_match('/^[a-z][a-z0-9_]*$/', $n) === 1))->toBeTrue();
});
