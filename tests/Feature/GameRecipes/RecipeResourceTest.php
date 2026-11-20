<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Support\RecipeStatusOptions;
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

it('bulk-deletes recipes for orga through the deleteAny policy and cascades the file rows', function () {
    $recipes = GameRecipe::factory()->count(2)->create();
    GameRecipeFile::factory()->for($recipes[0], 'recipe')->create();
    GameRecipeFile::factory()->for($recipes[1], 'recipe')->create();
    $this->actingAs($this->orga);

    Livewire::test(ListRecipes::class)
        ->selectTableRecords($recipes->modelKeys())
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(GameRecipe::query()->count())->toBe(0)
        ->and(GameRecipeFile::query()->count())->toBe(0);
});

it('forbids non-orga from the edit page', function () {
    $recipe = GameRecipe::factory()->published()->create();

    $this->actingAs(User::factory()->create())
        ->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertForbidden();
});

it('forbids non-orga from the create page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/recipes/create')
        ->assertForbidden();
});

it('gates the status select on the review policy instead of exposing a publish path', function () {
    $participant = User::factory()->create();
    $draftOnly = [RecipeStatus::Draft->value => __('game_recipes.status.draft')];

    expect(RecipeStatusOptions::for($participant))->toBe($draftOnly)
        ->and(RecipeStatusOptions::for(null))->toBe($draftOnly)
        ->and(RecipeStatusOptions::canReview($participant))->toBeFalse();

    $this->actingAs($this->orga);

    expect(RecipeStatusOptions::canReview($this->orga))->toBeTrue()
        ->and(RecipeStatusOptions::for($this->orga))->toHaveKeys([
            RecipeStatus::Draft->value,
            RecipeStatus::Published->value,
            RecipeStatus::Archived->value,
        ]);
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
        ->and($recipe->source)->toBe(GameRecipe::SOURCE_PANEL)
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

it('keeps the original review stamp when a published recipe is edited again', function () {
    $originalReviewer = User::factory()->orga()->create();
    $recipe = GameRecipe::factory()->publishedBy($originalReviewer)->create();
    $originalStamp = $recipe->fresh()->reviewed_at;
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['summary' => 'Neu geschrieben.'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $recipe->fresh();

    expect($fresh->status)->toBe(RecipeStatus::Published)
        ->and($fresh->reviewed_by)->toBe($originalReviewer->id)
        ->and($fresh->reviewed_at?->equalTo($originalStamp))->toBeTrue();
});

it('stamps the current reviewer when an archived recipe is republished through the select', function () {
    $oldReviewer = User::factory()->orga()->create();
    $recipe = GameRecipe::factory()->archived()->create([
        'reviewed_by' => $oldReviewer->id,
        'reviewed_at' => now()->subWeek(),
    ]);
    $oldStamp = $recipe->fresh()->reviewed_at;
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['status' => RecipeStatus::Published->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $recipe->fresh();

    expect($fresh->status)->toBe(RecipeStatus::Published)
        ->and($fresh->reviewed_by)->toBe($this->orga->id)
        ->and($fresh->reviewed_at?->greaterThan($oldStamp))->toBeTrue();
});

it('does not invent a review stamp when a synced published recipe is edited', function () {
    $recipe = GameRecipe::factory()->syncedFromBundle()->create();
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['summary' => 'Neu geschrieben.'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $recipe->fresh();

    expect($fresh->status)->toBe(RecipeStatus::Published)
        ->and($fresh->reviewed_by)->toBeNull()
        ->and($fresh->reviewed_at)->toBeNull();
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

it('removes file rows whose requirement was deleted and keeps the untouched ones', function () {
    $recipe = GameRecipe::factory()->draft()->withRequirements([
        ['role' => 'iso', 'label' => 'Disc', 'match' => ['*.iso'], 'required' => true],
        ['role' => 'patch', 'label' => 'Patch', 'match' => ['*.exe']],
        ['role' => 'mod', 'label' => 'Mod', 'match' => ['*.pk3']],
    ])->create();
    GameRecipeFile::factory()->for($recipe, 'recipe')->iso()->create(['label' => 'Disc']);
    GameRecipeFile::factory()->for($recipe, 'recipe')->create(['label' => 'Patch', 'sort' => 1]);
    GameRecipeFile::factory()->for($recipe, 'recipe')->create(['label' => 'Mod', 'sort' => 2]);

    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm([
            'requirements' => [
                ['role' => 'iso', 'label' => 'Disc', 'match' => ['*.iso'], 'required' => true],
                ['role' => 'mod', 'label' => 'Mod', 'match' => ['*.pk3']],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $files = $recipe->fresh()->files;

    expect($files)->toHaveCount(2)
        ->and($files[0]->label)->toBe('Disc')
        ->and($files[0]->sort)->toBe(0)
        ->and($files[1]->label)->toBe('Mod')
        ->and($files[1]->sort)->toBe(1);
});

it('hydrates a pinned LAN file from the file rows into the edit form', function () {
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
        ->assertOk()
        ->assertFormSet(function (array $state) use ($shared): array {
            $requirement = array_values($state['requirements'])[0];

            expect((int) $requirement['shared_file_id'])->toBe($shared->id);

            return [];
        });
});

it('keeps a hydrated pin on a plain save and never writes it into the requirements JSON', function () {
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
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $recipe->fresh();

    expect($fresh->files->sole()->shared_file_id)->toBe($shared->id)
        ->and($fresh->requirements[0])->not->toHaveKey('shared_file_id');
});

it('clears a pin when the form field is emptied', function () {
    $shared = SharedFile::factory()->approved()->create();
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

    expect($recipe->fresh()->files->sole()->shared_file_id)->toBeNull();
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

    $fresh = $recipe->fresh();

    expect($fresh->files->sole()->shared_file_id)->toBe($shared->id)
        ->and($fresh->requirements[0])->not->toHaveKey('shared_file_id');
});

it('rejects a pin that points at a missing LAN file with a form error', function () {
    $recipe = GameRecipe::factory()->draft()->create(['requirements' => []]);
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm([
            'requirements' => [
                ['role' => 'iso', 'label' => 'Disc', 'shared_file_id' => 999999],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['requirements.0.shared_file_id' => 'exists']);

    expect($recipe->fresh()->requirements)->toBe([]);
});

it('clears a legacy pin that only lives in the requirements JSON', function () {
    $recipe = GameRecipe::factory()->draft()->withRequirements([
        ['role' => 'iso', 'label' => 'Disc', 'match' => ['*.iso'], 'shared_file_id' => 999999],
    ])->create();
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertFormSet(function (array $state): array {
            expect(array_values($state['requirements'])[0]['shared_file_id'])->toBeNull();

            return [];
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipe->fresh()->requirements[0])->not->toHaveKey('shared_file_id');
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
