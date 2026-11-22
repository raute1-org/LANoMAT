<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use App\Modules\GameRecipes\Support\LauncherSupport;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function nfsu2Recipe(): GameRecipe
{
    $result = app(SyncRecipes::class)->handle(config('recipes.source'), only: 'need-for-speed-underground-2');

    expect($result['skipped'])->toBe([]);

    return GameRecipe::query()->where('slug', 'need-for-speed-underground-2')->sole();
}

/** @return array<string, mixed> */
function nfsu2Plan(GameRecipe $recipe, string $os): array
{
    return app(BuildInstallPlan::class)->handle($recipe->load('files'), User::factory()->create(), $os);
}

it('ships the native NFSU2 recipe with the NFS-Online requirements and targets', function () {
    $recipe = nfsu2Recipe();

    expect($recipe->platforms)->toBe(['windows', 'linux'])
        ->and($recipe->version)->toBe('1.2 + NFS Online')
        ->and($recipe->aliases)->toContain('nfsu2')
        ->and(app(LauncherSupport::class)->supports($recipe))->toBeTrue();

    $base = $recipe->files->first(static fn ($file): bool => $file->role->value === 'base' && $file->os() === 'windows');
    $linuxBase = $recipe->files->first(static fn ($file): bool => $file->role->value === 'base' && $file->os() === 'linux');
    $asi = $recipe->files->first(static fn ($file): bool => $file->label === 'NFS-Online client plugin (net_u2.asi)');

    expect($base)->not->toBeNull()
        ->and($base->required)->toBeTrue()
        ->and($base->patterns())->toContain('nfsu2-portable*.zip')
        ->and($linuxBase)->not->toBeNull()
        ->and($linuxBase->required)->toBeTrue()
        ->and($asi)->not->toBeNull()
        ->and($asi->required)->toBeFalse();

    $targets = collect($recipe->metadata['launch_targets'] ?? [])->keyBy('id');

    expect($targets['windows-direct']['os'])->toBe('windows')
        ->and($targets['windows-direct']['command'])->toContain('{{target}}/SPEED2.EXE')
        ->and($targets['linux-wine']['os'])->toBe('linux')
        ->and($targets['linux-wine']['command'])->toContain('WINEDLLOVERRIDES="dinput8=n,b" wine')
        ->and($targets['linux-proton']['command'])->toContain('proton run');
});

it('builds a windows plan with the base, the verify paths and the NFS-Online step', function () {
    $recipe = nfsu2Recipe();
    $event = Event::factory()->registration()->create();

    $base = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'nfsu2-portable.zip',
    ]);
    $asi = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'net_u2.asi',
    ]);

    $recipe->files
        ->first(static fn ($file): bool => $file->role->value === 'base' && $file->os() === 'windows')
        ->forceFill(['shared_file_id' => $base->id])
        ->save();
    $recipe->files
        ->first(static fn ($file): bool => $file->label === 'NFS-Online client plugin (net_u2.asi)')
        ->forceFill(['shared_file_id' => $asi->id])
        ->save();

    $plan = nfsu2Plan($recipe->refresh(), 'windows');

    $baseFile = collect($plan['files'])->firstWhere('label', 'NFSU2 portable 1.2 (Windows game tree, no install)');
    $asiFile = collect($plan['files'])->firstWhere('label', 'NFS-Online client plugin (net_u2.asi)');

    expect($baseFile['status'])->toBe('matched')
        ->and($baseFile['os'])->toBe('windows')
        ->and($asiFile['status'])->toBe('matched')
        ->and($plan['missing'])->toBe([])
        ->and($plan['target_dir'])->toBe('C:\\Games\\Need for Speed Underground 2');

    $steps = collect($plan['steps'])->keyBy('id');
    $verify = collect($plan['steps'])->flatMap(fn (array $step) => $step['verify'])->pluck('path');

    expect($steps->keys()->all())->toContain('install-base', 'install-nfs-online', 'check-install')
        ->and($steps->keys()->all())->not->toContain('install-base-linux')
        ->and($verify->all())->toContain(
            '{{target}}/SPEED2.EXE',
            '{{target}}/scripts/net_u2.asi',
            '{{target}}/scripts/net_u2.ini',
            '{{target}}/dinput8.dll',
        )
        ->and($plan['recipe']['launch_targets'])->toHaveCount(3);
});

it('builds a linux plan that keeps the same base and picks the wine launch target', function () {
    $recipe = nfsu2Recipe();
    $event = Event::factory()->registration()->create();

    $base = SharedFile::factory()->approved()->create([
        'event_id' => $event->id,
        'original_name' => 'NFSU2-portable.zip',
    ]);

    $recipe->files
        ->first(static fn ($file): bool => $file->role->value === 'base' && $file->os() === 'linux')
        ->forceFill(['shared_file_id' => $base->id])
        ->save();

    $plan = nfsu2Plan($recipe->refresh(), 'linux');

    $baseFile = collect($plan['files'])->firstWhere('label', 'NFSU2 portable 1.2 (same Windows tree under Wine)');

    expect($baseFile['status'])->toBe('matched')
        ->and($baseFile['os'])->toBe('linux')
        ->and($plan['target_dir'])->toBe('~/Games/need-for-speed-underground-2');

    $steps = collect($plan['steps'])->keyBy('id');

    expect($steps->keys()->all())->toContain('install-base-linux', 'install-nfs-online-linux')
        ->and($steps->keys()->all())->not->toContain('install-base')
        ->and($recipe->launchTarget(os: 'linux'))->toContain('wine');
});

/** A permanent, ready server row of the game the NFSU2 recipe installs. */
function nfsu2Server(string $address, int $port = 20922): ServerLink
{
    $game = Game::query()->updateOrCreate(
        ['slug' => 'need-for-speed-underground-2'],
        ['name' => 'Need for Speed: Underground 2', 'min_team_size' => 1, 'max_team_size' => 6],
    );

    return ServerLink::factory()->create([
        'game_id' => $game->id,
        'title' => 'NFSU2 (NFS-Online, unser Server)',
        'permanent' => true,
        'status' => ServerLinkStatus::Ready,
        'source' => ServerSource::External,
        'join_info' => new JoinInfo(address: $address, port: $port),
    ]);
}

it('writes the LAN host the plan carries into net_u2.ini on windows and linux', function () {
    $recipe = nfsu2Recipe();
    nfsu2Server('203.0.113.9');

    $windows = collect(nfsu2Plan($recipe, 'windows')['steps'])->keyBy('id')['install-nfs-online'];
    $linux = collect(nfsu2Plan($recipe, 'linux')['steps'])->keyBy('id')['install-nfs-online-linux'];

    $windowsRun = $windows['commands'][0]['run'];
    $linuxRun = $linux['commands'][2]['run'];

    expect($windowsRun)->toContain("\$serverHost = '203.0.113.9'")
        ->and($windowsRun)->toContain('if ($serverHost -ne \'\')')
        ->and($linuxRun)->toContain("host='203.0.113.9'")
        ->and($linuxRun)->toContain('if [ -n "$host" ]')
        // The ports are fixed by the server software, never the plan variable.
        ->and($windowsRun)->toContain('bootstrap_port = 20921')
        ->and($windowsRun)->toContain('race_port = 20000')
        ->and($linuxRun)->toContain('race_port = 20000')
        ->and($windowsRun)->not->toContain('{{server_host}}')
        ->and($linuxRun)->not->toContain('{{server_host}}');
});

it('leaves the host lines alone when the plan carries no server', function () {
    $recipe = nfsu2Recipe();

    $windows = collect(nfsu2Plan($recipe, 'windows')['steps'])->keyBy('id')['install-nfs-online'];
    $linux = collect(nfsu2Plan($recipe, 'linux')['steps'])->keyBy('id')['install-nfs-online-linux'];

    expect($windows['commands'][0]['run'])->toContain("\$serverHost = ''")
        ->and($windows['commands'][0]['run'])->toContain('if ($serverHost -ne \'\')')
        ->and($linux['commands'][2]['run'])->toContain("host=''")
        ->and($linux['commands'][2]['run'])->toContain('if [ -n "$host" ]')
        // Both steps tell the assistant where the address comes from instead.
        ->and($windows['detail'])->toContain('/servers')
        ->and($linux['detail'])->toContain('/servers');
});

it('names no address of its own and exports text the pull request gate accepts', function () {
    $recipe = nfsu2Recipe();

    expect(json_encode($recipe->metadata))->not->toContain('live_url')
        ->and(json_encode($recipe->steps))->not->toContain('10.23.')
        ->and((string) $recipe->skill_markdown)->not->toContain('10.23.');

    $problems = app(ProposeRecipe::class)->preview($recipe);

    // Nothing of the catalogue gate objects, and no address class in particular.
    expect($problems)->toBe([]);
});
