<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ProposeRecipe;
use App\Modules\GameRecipes\Actions\ShareGame;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orga = User::factory()->orga()->create();
    Config::set('recipes.pull_requests.trigger', 'publish');
});

/**
 * A recipe as a participant contributes it through the MCP tool: barely a
 * title, one requirement and one step. The catalogue would refuse it as a
 * draft and once published.
 */
function catalogPreviewBareDraft(string $slug = 'bare-game'): GameRecipe
{
    return GameRecipe::factory()->contributedBy(User::factory()->create())->create([
        'slug' => $slug,
        'title' => Str::headline($slug),
        'skill_markdown' => "# {$slug}\n\nInstall it.\n",
        'requirements' => [[
            'role' => 'base',
            'label' => 'Bare game archive',
            'match' => [$slug.'.zip'],
            'required' => true,
        ]],
        'steps' => [[
            'id' => 'install',
            'title' => 'Install',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Unpack the archive.',
        ]],
    ]);
}

it('shows what blocks a contributed draft as a draft and once published', function () {
    fakeRecipeRepository();
    $recipe = catalogPreviewBareDraft();
    $this->actingAs($this->orga);

    $draft = app(ProposeRecipe::class)->preview($recipe);
    $published = app(ProposeRecipe::class)->preview($recipe, RecipeStatus::Published);

    expect(implode("\n", $draft))->toContain('The draft has 1 step')
        ->and(implode("\n", $published))->not->toContain('The draft has 1 step');

    $this->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertSee(__('recipe_pull_requests.section'))
        ->assertSee(__('recipe_pull_requests.preview.heading'))
        ->assertSee(__('recipe_pull_requests.preview.draft'))
        ->assertSee(__('recipe_pull_requests.preview.published'))
        // The draft-only rule and a rule both statuses break.
        ->assertSee('The draft has 1 step')
        ->assertSee('SKILL.md has')
        // Which list decides the pull request with the default trigger.
        ->assertSee(__('recipe_pull_requests.preview.published_help_publish'))
        ->assertDontSee(__('recipe_pull_requests.preview.ready'));
});

it('says the text is ready for the catalogue when nothing blocks it', function () {
    fakeRecipeRepository();
    $user = User::factory()->create();
    $archive = SharedFile::factory()->approved()->create([
        'user_id' => $user->id,
        'event_id' => Event::factory()->registration()->create()->id,
        'original_name' => 'tiny-racer.zip',
    ]);

    $recipe = app(ShareGame::class)->handle($user, [
        'title' => 'Tiny Racer',
        'shared_file_id' => $archive->id,
        'platforms' => ['windows', 'linux'],
        'executable' => 'bin/TinyRacer.exe',
        'linux_runner' => 'wine',
        'multiplayer' => true,
    ]);

    expect(app(ProposeRecipe::class)->preview($recipe))->toBe([]);

    $this->actingAs($this->orga)
        ->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertSee(__('recipe_pull_requests.preview.ready'))
        ->assertDontSee(__('recipe_pull_requests.preview.draft'))
        ->assertDontSee(__('recipe_pull_requests.preview.published'));
});

it('hides the preview while pull requests are not configured', function () {
    $recipe = catalogPreviewBareDraft();
    $this->actingAs($this->orga);

    $this->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertDontSee(__('recipe_pull_requests.section'))
        ->assertDontSee(__('recipe_pull_requests.preview.heading'))
        ->assertDontSee('SKILL.md has');
});

it('hides the preview for a recipe the pipeline does not take', function () {
    fakeRecipeRepository();
    $recipe = GameRecipe::factory()->syncedFromBundle()->create();
    $this->actingAs($this->orga);

    $this->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertDontSee(__('recipe_pull_requests.section'))
        ->assertDontSee(__('recipe_pull_requests.preview.heading'));
});

it('shows a reason that carries participant text escaped, as text', function () {
    fakeRecipeRepository();
    $recipe = GameRecipe::factory()->contributedBy(User::factory()->create())->create([
        'slug' => 'scripted-game',
        'title' => 'Scripted Game',
        'requirements' => [[
            'role' => 'mod',
            'label' => '<script>alert(1)</script>',
            'match' => ['mods/extra.pk3'],
        ]],
    ]);

    $reason = collect(app(ProposeRecipe::class)->preview($recipe))
        ->first(fn (string $problem): bool => str_contains($problem, '<script>alert(1)</script>'));

    expect($reason)->toBeString();

    $this->actingAs($this->orga)
        ->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        // The full check message, escaped, as readable text.
        ->assertSee((string) $reason)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('shows the launcher checks next to the catalogue preview on the edit page', function () {
    // Both read-only sections describe the same recipe; a reviewed draft shows
    // the pull request preview without losing the launcher block.
    fakeRecipeRepository();
    $recipe = catalogPreviewBareDraft();
    $this->actingAs($this->orga);

    $this->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertSee(__('game_recipes.launcher.section'))
        ->assertSee(__('recipe_pull_requests.preview.heading'));
});

it('shows the requirement keys that have no form field and keeps them across a save', function () {
    $requirements = [[
        'role' => 'mod',
        'label' => 'High-res pack',
        'match' => ['hd*.pk3'],
        'os' => 'windows',
        'manual' => true,
        'notes' => '<b>von Hand</b> von der LAN-Disc kopieren',
        'author' => 'Team Example',
        'license' => 'CC-BY-SA',
        'tags' => ['hd', 'textures'],
    ]];
    $recipe = GameRecipe::factory()->draft()->create(['requirements' => $requirements]);
    $this->actingAs($this->orga);

    $this->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertSee(__('game_recipes.requirement_details.os', ['os' => 'Windows']))
        ->assertSee(__('game_recipes.requirement_details.manual'))
        ->assertSee(__('game_recipes.requirement_details.notes', ['value' => '<b>von Hand</b> von der LAN-Disc kopieren']))
        ->assertSee(__('game_recipes.requirement_details.author', ['value' => 'Team Example']))
        ->assertSee(__('game_recipes.requirement_details.license', ['value' => 'CC-BY-SA']))
        ->assertSee(__('game_recipes.requirement_details.tags', ['value' => 'hd, textures']))
        // Participant text in a hint is shown, not rendered.
        ->assertDontSee('<b>von Hand</b> von der LAN-Disc kopieren', false);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $recipe->fresh();

    expect($fresh->requirements[0])->toMatchArray([
        'os' => 'windows',
        'manual' => true,
        'notes' => '<b>von Hand</b> von der LAN-Disc kopieren',
        'author' => 'Team Example',
        'license' => 'CC-BY-SA',
        'tags' => ['hd', 'textures'],
    ])->not->toHaveKey('requirement_details_display');
});
