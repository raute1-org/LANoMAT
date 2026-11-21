<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Lighting\Enums\LightingTrigger;
use App\Modules\Lighting\Filament\Resources\LightingRules\Pages\CreateLightingRule;
use App\Modules\Lighting\Filament\Resources\LightingRules\Pages\ListLightingRules;
use App\Modules\Lighting\Filament\Resources\LightingScenes\LightingSceneResource;
use App\Modules\Lighting\Filament\Resources\LightingScenes\Pages\CreateLightingScene;
use App\Modules\Lighting\Filament\Resources\LightingScenes\Pages\EditLightingScene;
use App\Modules\Lighting\Filament\Resources\LightingScenes\Pages\ListLightingScenes;
use App\Modules\Lighting\Models\LightingRule;
use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Testing\FakeLightingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps participants and helpers out of the lighting resources', function () {
    $scene = LightingScene::query()->firstOrFail();

    foreach ([User::factory()->create(), User::factory()->helper()->create()] as $user) {
        $this->actingAs($user);
        $this->get('/admin/lighting-scenes')->assertForbidden();
        $this->get('/admin/lighting-scenes/create')->assertForbidden();
        $this->get("/admin/lighting-scenes/{$scene->id}/edit")->assertForbidden();
        $this->get('/admin/lighting-rules')->assertForbidden();
    }
});

it('lets orga open the lighting resources', function () {
    $scene = LightingScene::query()->firstOrFail();
    $this->actingAs(User::factory()->orga()->create());

    $this->get('/admin/lighting-scenes')->assertOk()->assertSee('Rot-Alarm');
    $this->get('/admin/lighting-scenes/create')->assertOk();
    $this->get("/admin/lighting-scenes/{$scene->id}/edit")->assertOk();
    $this->get('/admin/lighting-rules')->assertOk();
    $this->get('/admin/lighting-rules/create')->assertOk();
});

it('creates a scene with all-light and per-light states', function () {
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateLightingScene::class)
        ->fillForm([
            'name' => 'Test',
            'states' => ['all' => ['hex' => '#112233', 'brightness' => 0.5]],
            'fixture_states' => [['id' => 2, 'hex' => '#ff0000', 'brightness' => null]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $scene = LightingScene::query()->where('name', 'Test')->firstOrFail();
    expect($scene->states['all']['hex'])->toBe('#112233')
        ->and($scene->states['2'])->toBe(['hex' => '#ff0000'])
        ->and($scene->pattern)->toBeNull();
});

it('keeps per-light states when a scene is edited', function () {
    $scene = LightingScene::query()->create(['name' => 'Mix', 'states' => ['all' => ['hex' => '#112233'], '3' => ['hex' => '#00ff00']]]);
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditLightingScene::class, ['record' => $scene->getKey()])
        ->assertFormSet(fn (array $state) => expect(array_values($state['fixture_states']))->toBe([['id' => 3, 'hex' => '#00ff00', 'brightness' => null]]))
        ->fillForm(['name' => 'Mix 2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($scene->fresh()->states['3']['hex'])->toBe('#00ff00')->and($scene->fresh()->name)->toBe('Mix 2');
});

it('explodes and prunes the scene form data symmetrically', function () {
    $data = LightingSceneResource::explode(['states' => ['all' => ['hex' => '#fff'], '1' => ['hex' => '#000']]]);

    expect($data['fixture_states'])->toBe([['id' => 1, 'hex' => '#000']])->and(array_keys($data['states']))->toBe(['all']);
    expect(LightingSceneResource::prune($data)['states'])->toBe(['all' => ['hex' => '#fff'], '1' => ['hex' => '#000']]);
});

it('creates a rule that is inactive by default and plays a scene from the table', function () {
    $fake = FakeLightingClient::install();
    $scene = LightingScene::query()->where('key', 'off')->firstOrFail();
    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(CreateLightingRule::class)
        ->fillForm(['trigger' => LightingTrigger::MatchReady->value, 'lighting_scene_id' => $scene->id, 'cooldown_seconds' => 10])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(LightingRule::query()->firstOrFail()->active)->toBeFalse();

    Livewire::test(ListLightingScenes::class)->callTableAction('play', $scene);
    expect($fake->callsTo('blackout'))->toHaveCount(1);

    Livewire::test(ListLightingRules::class)->assertCanSeeTableRecords(LightingRule::all());
});
