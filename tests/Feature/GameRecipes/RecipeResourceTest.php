<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orga = User::factory()->orga()->create();
});

it('lists recipes for orga with german labels', function () {
    GameRecipe::factory()->published()->create(['title' => 'Unreal Tournament 2004']);

    $this->actingAs($this->orga)
        ->get('/admin/recipes')
        ->assertOk()
        ->assertSee('Unreal Tournament 2004')
        ->assertSee('Spielrezepte'); // i18n gate
});

it('forbids participants and helpers from the recipe resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/recipes')
        ->assertForbidden();

    $this->actingAs(User::factory()->helper()->create())
        ->get('/admin/recipes')
        ->assertForbidden();
});

it('shows contributed drafts with their contributor and status label in the list', function () {
    $contributor = User::factory()->create(['name' => 'Nils Teilnehmer']);
    GameRecipe::factory()->contributedBy($contributor)->create(['title' => 'Quake Live']);

    $this->actingAs($this->orga)
        ->get('/admin/recipes')
        ->assertOk()
        ->assertSee('Quake Live')
        ->assertSee('Nils Teilnehmer')
        ->assertSee('Entwurf');
});

it('bulk-deletes recipes for orga through the deleteAny policy', function () {
    $recipes = GameRecipe::factory()->count(2)->create();
    $this->actingAs($this->orga);

    Livewire::test(ListRecipes::class)
        ->selectTableRecords($recipes->modelKeys())
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(GameRecipe::query()->count())->toBe(0);
});

it('forbids non-orga from the edit page', function () {
    $recipe = GameRecipe::factory()->published()->create();

    $this->actingAs(User::factory()->create())
        ->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertForbidden();
});

it('renders the create page with the draft status preselected', function () {
    $this->actingAs($this->orga);

    Livewire::test(CreateRecipe::class)
        ->assertOk()
        ->assertFormSet(['status' => RecipeStatus::Draft->value]);
});

it('creates a published recipe through the form and records the reviewer', function () {
    $this->actingAs($this->orga);

    Livewire::test(CreateRecipe::class)
        ->fillForm([
            'slug' => 'ut2004',
            'title' => 'Unreal Tournament 2004',
            'status' => RecipeStatus::Published->value,
            'aliases' => ['ut2k4', 'ut 2k4'],
            'platforms' => ['windows', 'linux'],
            'summary' => 'Der LAN-Klassiker.',
            'requirements' => [
                ['role' => 'iso', 'label' => 'Disc 1', 'match' => ['ut2004*.iso'], 'required' => true],
                ['role' => 'patch', 'label' => 'Patch 3369', 'match' => ['ut2004patch*.exe'], 'url' => 'https://example.test/patch.exe'],
            ],
            'steps' => [
                [
                    'id' => 'install',
                    'title' => 'Installieren',
                    'os' => 'windows',
                    'optional' => false,
                    'detail' => 'Setup ausführen.',
                    'commands' => [['shell' => 'powershell', 'run' => 'setup.exe /silent']],
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $recipe = GameRecipe::query()->where('slug', 'ut2004')->sole();

    expect($recipe->status)->toBe(RecipeStatus::Published)
        ->and($recipe->reviewed_by)->toBe($this->orga->id)
        ->and($recipe->reviewed_at)->not->toBeNull()
        ->and($recipe->source)->toBe('panel')
        ->and($recipe->aliases)->toBe(['ut2k4', 'ut 2k4'])
        ->and($recipe->platforms)->toBe(['windows', 'linux'])
        ->and($recipe->steps)->toHaveCount(1)
        ->and($recipe->steps[0]['commands'][0]['run'])->toBe('setup.exe /silent');
});

it('creates a draft without review metadata by default', function () {
    $this->actingAs($this->orga);

    Livewire::test(CreateRecipe::class)
        ->fillForm([
            'slug' => 'quake-3',
            'title' => 'Quake III Arena',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $recipe = GameRecipe::query()->where('slug', 'quake-3')->sole();

    expect($recipe->status)->toBe(RecipeStatus::Draft)
        ->and($recipe->reviewed_by)->toBeNull()
        ->and($recipe->reviewed_at)->toBeNull();
});

it('rejects a duplicate slug with a form error instead of a database exception', function () {
    GameRecipe::factory()->create(['slug' => 'ut2004']);
    $this->actingAs($this->orga);

    Livewire::test(CreateRecipe::class)
        ->fillForm([
            'slug' => 'ut2004',
            'title' => 'Doppelt',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('renders the edit page with the stored requirements', function () {
    $recipe = GameRecipe::factory()->draft()->withRequirements([
        ['role' => 'iso', 'label' => 'Disc', 'match' => ['*.iso'], 'required' => true],
    ])->create();

    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertOk()
        ->assertFormSet(function (array $state) use ($recipe): array {
            expect($state['slug'])->toBe($recipe->slug)
                ->and($state['status'])->toBe(RecipeStatus::Draft->value)
                ->and(array_values($state['requirements']))->toHaveCount(1);

            return [];
        })
        ->assertActionVisible('publish');
});

it('publishes a draft from the header action and stamps the reviewer', function () {
    $recipe = GameRecipe::factory()->draft()->create();
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionVisible('publish')
        ->callAction('publish')
        ->assertHasNoFormErrors();

    $fresh = $recipe->fresh();

    expect($fresh->status)->toBe(RecipeStatus::Published)
        ->and($fresh->reviewed_by)->toBe($this->orga->id)
        ->and($fresh->reviewed_at)->not->toBeNull();
});

it('archives a published recipe from the header action', function () {
    $recipe = GameRecipe::factory()->published()->create();
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionVisible('archive')
        ->assertActionHidden('publish')
        ->callAction('archive');

    expect($recipe->fresh()->status)->toBe(RecipeStatus::Archived);
});

it('stamps the reviewer when an orga publishes through the status select', function () {
    $recipe = GameRecipe::factory()->draft()->create();
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['status' => RecipeStatus::Published->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $recipe->fresh();

    expect($fresh->status)->toBe(RecipeStatus::Published)
        ->and($fresh->reviewed_by)->toBe($this->orga->id)
        ->and($fresh->reviewed_at)->not->toBeNull();
});

it('syncs the requirements repeater into normalized file rows in order', function () {
    $recipe = GameRecipe::factory()->draft()->create(['requirements' => []]);
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm([
            'requirements' => [
                ['role' => 'iso', 'label' => 'Disc', 'match' => ['ut2004*.iso'], 'required' => true, 'size_bytes' => 123456],
                ['role' => 'avatar', 'label' => 'Xan', 'match' => ['xan*.vrm'], 'required' => false],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $files = $recipe->fresh()->files;

    expect($files)->toHaveCount(2)
        ->and($files[0]->role)->toBe(RecipeFileRole::Iso)
        ->and($files[0]->label)->toBe('Disc')
        ->and($files[0]->match_patterns)->toBe(['ut2004*.iso'])
        ->and($files[0]->required)->toBeTrue()
        ->and($files[0]->size_bytes)->toBe(123456)
        ->and($files[0]->sort)->toBe(0)
        ->and($files[1]->role)->toBe(RecipeFileRole::Avatar)
        ->and($files[1]->sort)->toBe(1);
});

it('removes file rows whose requirement was deleted and keeps the rest', function () {
    $recipe = GameRecipe::factory()->draft()->withRequirements([
        ['role' => 'iso', 'label' => 'Disc', 'match' => ['*.iso'], 'required' => true],
        ['role' => 'patch', 'label' => 'Patch', 'match' => ['*.exe']],
    ])->create();
    GameRecipeFile::factory()->for($recipe, 'recipe')->iso()->create(['label' => 'Disc']);
    GameRecipeFile::factory()->for($recipe, 'recipe')->create(['label' => 'Patch', 'sort' => 1]);

    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm([
            'requirements' => [
                ['role' => 'iso', 'label' => 'Disc', 'match' => ['*.iso'], 'required' => true],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $files = $recipe->fresh()->files;

    expect($files)->toHaveCount(1)
        ->and($files[0]->label)->toBe('Disc');
});

it('preserves a pinned LAN file when the form round-trips without the pin', function () {
    $shared = SharedFile::factory()->approved()->create(['original_name' => 'ut2004.iso']);
    $recipe = GameRecipe::factory()->draft()->withRequirements([
        ['role' => 'iso', 'label' => 'Disc', 'match' => ['*.iso'], 'required' => true],
    ])->create();
    GameRecipeFile::factory()->for($recipe, 'recipe')->iso()->create([
        'label' => 'Disc',
        'shared_file_id' => $shared->id,
    ]);

    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm([
            'requirements' => [
                ['role' => 'iso', 'label' => 'Disc', 'match' => ['*.iso'], 'required' => true],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $file = $recipe->fresh()->files->sole();

    expect($file->shared_file_id)->toBe($shared->id);
});

it('takes an explicit pin from the requirement form', function () {
    $shared = SharedFile::factory()->approved()->create();
    $recipe = GameRecipe::factory()->draft()->create(['requirements' => []]);
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm([
            'requirements' => [
                ['role' => 'iso', 'label' => 'Disc', 'shared_file_id' => $shared->id],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipe->fresh()->files->sole()->shared_file_id)->toBe($shared->id);
});

it('keeps avatar metadata that has no form field across a plain save', function () {
    $recipe = GameRecipe::factory()->draft()->withRequirements([
        [
            'role' => 'avatar',
            'label' => 'Xan',
            'match' => ['xan*.vrm'],
            'author' => 'Epic',
            'license' => 'CC-BY',
            'notes' => 'Community favourite',
            'tags' => ['skin'],
        ],
    ])->create();
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    $file = $recipe->fresh()->files->sole();

    expect($file->metadata)->toMatchArray([
        'author' => 'Epic',
        'license' => 'CC-BY',
        'notes' => 'Community favourite',
        'tags' => ['skin'],
    ]);
});
