<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    // This test is about launch metadata, not the curated lineup.
    Config::set('launcher.lineup', '');
});

/**
 * The games page must never hand `{{host}}` to a browser and must expose every
 * launch target, not just the default (merge log § 10, W9/W10 conflict).
 */
function launchContractRecipe(): GameRecipe
{
    return GameRecipe::query()->create([
        'slug' => 'ridge-racer-type-4',
        'title' => 'Ridge Racer Type 4',
        'aliases' => ['r4'],
        'version' => '1.0.0',
        'status' => 'published',
        'source' => 'bundled',
        'platforms' => ['windows', 'linux'],
        'summary' => 'Self-hosted browser cabinet.',
        'description' => 'Play in the browser or run the native recomp.',
        'skill_markdown' => "---\nname: r4\ndescription: Set up R4.\n---\n",
        'requirements' => [],
        'steps' => [['id' => 'install', 'title' => 'Install', 'os' => 'any', 'commands' => []]],
        'metadata' => [
            'genre' => 'racing',
            'players' => '1-50',
            'lan_recommended' => true,
            'tags' => ['racing'],
            'play_url' => 'http://{{host}}:8080/play?game=r4',
            'launch_targets' => [
                ['id' => 'flat', 'label' => 'Native', 'os' => 'windows', 'command' => '{{target}}/Ridge_Racer_4.exe'],
                ['id' => 'vr', 'label' => 'VR', 'os' => 'windows', 'command' => '{{target}}/Ridge_Racer_4_VR.exe'],
            ],
        ],
    ]);
}

it('substitutes the request host into play_url and lists every launch target', function () {
    $user = User::factory()->create();
    Event::factory()->registration()->create();
    launchContractRecipe();

    $this->actingAs($user)
        ->get('/games/r4')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Show')
            ->where('game.play_url', 'http://localhost:8080/play?game=r4')
            ->where('game.launch_target', '{{target}}/Ridge_Racer_4.exe')
            ->has('game.launch_targets', 2)
            ->where('game.launch_targets.0.id', 'flat')
            ->where('game.launch_targets.1.id', 'vr'));
});

it('never leaves a {{host}} placeholder in the list card payload', function () {
    $user = User::factory()->create();
    launchContractRecipe();

    $this->actingAs($user)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Index')
            ->where('games.0.play_url', 'http://localhost:8080/play?game=r4')
            ->has('games.0.launch_targets', 2));
});
