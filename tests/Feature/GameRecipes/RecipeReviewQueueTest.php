<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Modules\GameRecipes\Filament\Resources\Recipes\RecipeResource;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orga = User::factory()->orga()->create();
});

/** A shared game whose base archive is pinned to that LAN file. */
function reviewQueueContribution(string $slug, ?SharedFile $file = null): GameRecipe
{
    $recipe = GameRecipe::factory()
        ->contributedBy(User::factory()->create())
        ->create(['slug' => $slug, 'title' => Str::headline($slug)]);

    GameRecipeFile::factory()->for($recipe, 'recipe')->create([
        'role' => RecipeFileRole::Base,
        'label' => 'Portable archive',
        'match_patterns' => [$slug.'.zip'],
        'required' => true,
        'shared_file_id' => $file?->id,
    ]);

    return $recipe->refresh();
}

it('shows the contributor, the lineup switch, the pull request and the file state of a shared game', function () {
    $contributor = User::factory()->create(['name' => 'Nils Teilnehmer']);
    $pending = SharedFile::factory()->create(['original_name' => 'tiny-racer.zip']);
    $recipe = reviewQueueContribution('tiny-racer', $pending);
    $recipe->forceFill(['contributor_user_id' => $contributor->id])->save();
    $recipe->forceFill([
        'pr_number' => 7,
        'pr_url' => 'https://github.test/lanomat/pull/7',
        'pr_state' => PullRequestState::Open,
        'pr_synced_at' => now(),
    ])->save();
    $this->actingAs($this->orga);

    Livewire::test(ListRecipes::class)
        ->assertTableColumnExists('contributor.name')
        ->assertTableColumnExists('in_lineup')
        ->assertTableColumnExists('pr_state')
        ->assertTableColumnExists('contribution_file_state')
        ->assertTableColumnStateSet('in_lineup', false, $recipe)
        ->assertTableColumnFormattedStateSet('pr_state', __('recipe_pull_requests.state.open'), $recipe)
        ->assertTableColumnFormattedStateSet('contribution_file_state', __('game_recipes.contribution_file.pending'), $recipe);

    $this->get('/admin/recipes')
        ->assertOk()
        ->assertSee('Nils Teilnehmer')
        ->assertSee('Datei wartet auf Freigabe')
        ->assertSee('Pull Request offen')
        ->assertSee('https://github.test/lanomat/pull/7');
});

it('reports a rejected file and a missing required archive in the list', function () {
    $rejected = reviewQueueContribution('gone-game', SharedFile::factory()->rejected()->create());
    $missing = reviewQueueContribution('unpinned-game');
    $this->actingAs($this->orga);

    Livewire::test(ListRecipes::class)
        ->assertTableColumnFormattedStateSet('contribution_file_state', __('game_recipes.contribution_file.rejected'), $rejected)
        ->assertTableColumnFormattedStateSet('contribution_file_state', __('game_recipes.contribution_file.missing'), $missing);
});

it('shows no file state for a recipe the orga wrote itself', function () {
    $recipe = GameRecipe::factory()->published()->create();
    $this->actingAs($this->orga);

    Livewire::test(ListRecipes::class)
        ->assertTableColumnStateSet('contribution_file_state', null, $recipe);
});

it('narrows the list to shared games', function () {
    $contributed = reviewQueueContribution('tiny-racer');
    $curated = GameRecipe::factory()->published()->create();
    $this->actingAs($this->orga);

    Livewire::test(ListRecipes::class)
        ->filterTable('contributed', true)
        ->assertCanSeeTableRecords([$contributed])
        ->assertCanNotSeeTableRecords([$curated]);
});

it('narrows the list by the lineup switch in both directions', function () {
    $listed = reviewQueueContribution('tiny-racer');
    $listed->forceFill(['in_lineup' => true])->save();
    $unlisted = reviewQueueContribution('quiet-quake');
    $this->actingAs($this->orga);

    Livewire::test(ListRecipes::class)
        ->filterTable('in_lineup', true)
        ->assertCanSeeTableRecords([$listed])
        ->assertCanNotSeeTableRecords([$unlisted])
        ->filterTable('in_lineup', false)
        ->assertCanSeeTableRecords([$unlisted])
        ->assertCanNotSeeTableRecords([$listed]);
});

it('narrows the list by pull request state', function () {
    $open = reviewQueueContribution('tiny-racer');
    $open->forceFill(['pr_number' => 3, 'pr_url' => 'https://github.test/lanomat/pull/3', 'pr_state' => PullRequestState::Open])->save();
    $merged = reviewQueueContribution('settled-game');
    $merged->forceFill(['pr_number' => 4, 'pr_url' => 'https://github.test/lanomat/pull/4', 'pr_state' => PullRequestState::Merged])->save();
    $this->actingAs($this->orga);

    Livewire::test(ListRecipes::class)
        ->filterTable('pr_state', PullRequestState::Merged->value)
        ->assertCanSeeTableRecords([$merged])
        ->assertCanNotSeeTableRecords([$open]);
});

it('badges the navigation with the contributed drafts waiting for review', function () {
    reviewQueueContribution('tiny-racer');
    reviewQueueContribution('quiet-quake');
    // Neither a curated draft nor a contributed published recipe is a task.
    GameRecipe::factory()->draft()->create();
    GameRecipe::factory()->contributedBy(User::factory()->create())->create([
        'status' => RecipeStatus::Published,
    ]);

    expect(RecipeResource::getNavigationBadge())->toBe('2');
});

it('shows no navigation badge when nothing waits for review', function () {
    GameRecipe::factory()->draft()->create();

    expect(RecipeResource::getNavigationBadge())->toBeNull();
});

it('keeps the list query count flat as shared games are added', function () {
    $seed = function (int $from, int $to): void {
        for ($i = $from; $i <= $to; $i++) {
            $file = SharedFile::factory()->create(['original_name' => 'shared-'.$i.'.zip']);
            reviewQueueContribution('shared-game-'.$i, $file);
        }
    };

    $seed(1, 2);
    $this->actingAs($this->orga)->get('/admin/recipes')->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/admin/recipes')->assertOk();
    $small = $queries;

    $seed(3, 8);

    $queries = 0;
    $this->get('/admin/recipes')->assertOk();

    expect($queries)->toBe($small);
});

it('shows the pull request state of a recipe read-only on the edit page', function () {
    $recipe = GameRecipe::factory()->published()->create(['slug' => 'tiny-racer', 'title' => 'Tiny Racer']);
    $recipe->forceFill([
        'pr_number' => 7,
        'pr_url' => 'https://github.test/lanomat/pull/7',
        'pr_state' => PullRequestState::Open,
        'pr_synced_at' => now(),
    ])->save();
    $this->actingAs($this->orga);

    $this->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertSee(__('recipe_pull_requests.section'))
        ->assertSee(__('recipe_pull_requests.state.open'))
        ->assertSee('https://github.test/lanomat/pull/7')
        ->assertSee(__('recipe_pull_requests.synced_at', ['time' => '']), false);
});

it('names the reason when a proposal was blocked and keeps the url out', function () {
    $recipe = GameRecipe::factory()->draft()->create();
    $recipe->forceFill([
        'pr_state' => PullRequestState::Blocked,
        'pr_error' => 'Öffentliche IP-Adresse im Rezepttext.',
        'pr_synced_at' => now(),
    ])->save();
    $this->actingAs($this->orga);

    $this->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertSee(__('recipe_pull_requests.state.blocked'))
        ->assertSee('Öffentliche IP-Adresse im Rezepttext.');
});

it('hides the pull request section while nothing was ever proposed', function () {
    $recipe = GameRecipe::factory()->draft()->create();
    $this->actingAs($this->orga);

    $this->get('/admin/recipes/'.$recipe->id.'/edit')
        ->assertOk()
        ->assertDontSee(__('recipe_pull_requests.section'));
});

it('keeps the lineup switch on the edit form and explains it', function () {
    $recipe = GameRecipe::factory()->published()->create(['in_lineup' => true]);
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertFormSet(['in_lineup' => true])
        ->assertSee(__('game_recipes.fields.in_lineup_help'));
});
