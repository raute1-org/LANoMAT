<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\EditInfoscreenScene;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\ListInfoscreenScenes;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('forbids participants from the infoscreen scenes resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/infoscreen-scenes')
        ->assertForbidden();
});

it('forbids helpers from the infoscreen scenes resource', function () {
    $this->actingAs(User::factory()->helper()->create())
        ->get('/admin/infoscreen-scenes')
        ->assertForbidden();
});

it('allows orga into the infoscreen scenes resource and renders the list', function () {
    $event = Event::factory()->create();
    InfoscreenScene::factory()->for($event)->announcement()->create();

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/infoscreen-scenes')
        ->assertOk()
        ->assertSee('Ansage');
});

it('lets an orga toggle the enabled column inline, since InfoscreenScenePolicy::update allows it', function () {
    $event = Event::factory()->create();
    $scene = InfoscreenScene::factory()->for($event)->announcement()->create(['enabled' => false]);

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(ListInfoscreenScenes::class)
        ->assertTableColumnStateSet('enabled', false, record: $scene)
        ->call('updateTableColumnState', 'enabled', $scene->getKey(), true)
        ->assertTableColumnStateSet('enabled', true, record: $scene->fresh());
});

it('round-trips the announcement config through SceneConfigCast', function () {
    $event = Event::factory()->create();
    $scene = InfoscreenScene::factory()->for($event)->announcement()->create();

    $this->actingAs(User::factory()->orga()->create());

    Livewire::test(EditInfoscreenScene::class, ['record' => $scene->getRouteKey()])
        ->fillForm([
            'headline' => 'Gleich geht es weiter',
            'body' => 'Bitte Plätze einnehmen.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $scene->fresh();

    expect($fresh->config->headline)->toBe('Gleich geht es weiter')
        ->and($fresh->config->body)->toBe('Bitte Plätze einnehmen.');
});
