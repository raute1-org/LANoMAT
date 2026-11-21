<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\PublishRecipe;
use App\Modules\GameRecipes\Contracts\RecipeRepository;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Events\RecipePublished;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\GameRecipes\Jobs\ProposeRecipePullRequestJob;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Support\LauncherLineup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orga = User::factory()->orga()->create();
    Config::set('launcher.dist_path', base_path('clients-dist/__missing-for-tests__'));
});

/** A shared game: a draft contribution whose base archive is pinned. */
function publishRecipeContribution(User $contributor, ?SharedFile $file = null, string $slug = 'tiny-racer'): GameRecipe
{
    $recipe = GameRecipe::factory()
        ->contributedBy($contributor)
        ->portable('tiny-racer.zip', 'TinyRacer.exe')
        ->create(['slug' => $slug, 'title' => 'Tiny Racer']);

    GameRecipeFile::factory()->for($recipe, 'recipe')->create([
        'role' => RecipeFileRole::Base,
        'label' => 'Portable archive',
        'match_patterns' => ['tiny-racer.zip'],
        'required' => true,
        'shared_file_id' => $file?->id,
    ]);

    return $recipe->refresh();
}

function publishRecipePendingFile(string $name = 'tiny-racer.zip'): SharedFile
{
    return SharedFile::factory()->create(['original_name' => $name, 'size_bytes' => 12_300_000]);
}

it('publishes a contribution, stamps the reviewer, adds it to the lineup and announces it once', function () {
    Event::fake([RecipePublished::class]);
    $contributor = User::factory()->create();
    $recipe = publishRecipeContribution($contributor);

    app(PublishRecipe::class)->handle($recipe, $this->orga);

    $fresh = $recipe->fresh();

    expect($fresh->status)->toBe(RecipeStatus::Published)
        ->and($fresh->reviewed_by)->toBe($this->orga->id)
        ->and($fresh->reviewed_at)->not->toBeNull()
        ->and($fresh->in_lineup)->toBeTrue();

    Event::assertDispatchedTimes(RecipePublished::class, 1);
    Event::assertDispatched(fn (RecipePublished $event): bool => $event->recipe->is($fresh)
        && $event->reviewerId === $this->orga->id);
});

it('leaves the lineup flag of a non-contribution alone', function () {
    Event::fake([RecipePublished::class]);
    $recipe = GameRecipe::factory()->draft()->create(['in_lineup' => false]);

    app(PublishRecipe::class)->handle($recipe, $this->orga);

    expect($recipe->fresh()->status)->toBe(RecipeStatus::Published)
        ->and($recipe->fresh()->in_lineup)->toBeFalse();
});

it('keeps the lineup flag a curated recipe already has', function () {
    $recipe = GameRecipe::factory()->draft()->inLineup()->create();

    app(PublishRecipe::class)->handle($recipe, $this->orga);

    expect($recipe->fresh()->in_lineup)->toBeTrue();
});

it('does not announce or re-stamp a recipe that is already published', function () {
    Event::fake([RecipePublished::class]);
    $original = User::factory()->orga()->create();
    $recipe = GameRecipe::factory()->publishedBy($original)->create(['contributor_user_id' => null]);
    $stamp = $recipe->fresh()->reviewed_at;

    app(PublishRecipe::class)->handle($recipe, $this->orga);

    $fresh = $recipe->fresh();

    expect($fresh->reviewed_by)->toBe($original->id)
        ->and($fresh->reviewed_at?->equalTo($stamp))->toBeTrue();

    Event::assertNotDispatched(RecipePublished::class);
});

it('approves the pinned pending files with the reviewer while publishing', function () {
    Event::fake([RecipePublished::class]);
    $file = publishRecipePendingFile();
    $recipe = publishRecipeContribution(User::factory()->create(), $file);

    app(PublishRecipe::class)->handle($recipe, $this->orga, approveFiles: true);

    $fresh = $file->fresh();

    expect($fresh->visibility)->toBe(FileVisibility::Approved)
        ->and($fresh->reviewed_by)->toBe($this->orga->id)
        ->and($fresh->reviewed_at)->not->toBeNull()
        ->and($recipe->fresh()->status)->toBe(RecipeStatus::Published);

    Event::assertDispatchedTimes(RecipePublished::class, 1);
});

it('leaves approved and rejected pins untouched when approving files', function () {
    $approved = SharedFile::factory()->approved()->create(['original_name' => 'already.zip']);
    $rejected = SharedFile::factory()->rejected()->create(['original_name' => 'gone.zip']);
    $contributor = User::factory()->create();
    $recipe = publishRecipeContribution($contributor, $approved);

    GameRecipeFile::factory()->for($recipe, 'recipe')->create([
        'role' => RecipeFileRole::Mod,
        'label' => 'Extra mod',
        'match_patterns' => ['gone.zip'],
        'shared_file_id' => $rejected->id,
    ]);

    app(PublishRecipe::class)->handle($recipe->refresh(), $this->orga, approveFiles: true);

    expect($recipe->fresh()->status)->toBe(RecipeStatus::Published)
        // The approved file keeps the stamp of whoever released it first.
        ->and($approved->fresh()->reviewed_by)->not->toBe($this->orga->id)
        ->and($rejected->fresh()->visibility)->toBe(FileVisibility::Rejected);
});

it('surfaces the files gate as an authorization failure and publishes nothing', function () {
    $file = publishRecipePendingFile();
    $recipe = publishRecipeContribution(User::factory()->create(), $file);

    expect(fn () => app(PublishRecipe::class)->handle($recipe, User::factory()->create(), approveFiles: true))
        ->toThrow(AuthorizationException::class);

    expect($recipe->fresh()->status)->toBe(RecipeStatus::Draft)
        ->and($file->fresh()->visibility)->toBe(FileVisibility::Pending);
});

it('publishes from the header action through the action, lineup flag included', function () {
    Event::fake([RecipePublished::class]);
    $recipe = publishRecipeContribution(User::factory()->create());
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionVisible('publish')
        ->callAction('publish')
        ->assertNotified(__('game_recipes.actions.published'));

    expect($recipe->fresh()->status)->toBe(RecipeStatus::Published)
        ->and($recipe->fresh()->in_lineup)->toBeTrue();

    Event::assertDispatchedTimes(RecipePublished::class, 1);
});

it('approves the file and publishes in the one combined action', function () {
    Event::fake([RecipePublished::class]);
    $file = publishRecipePendingFile();
    $recipe = publishRecipeContribution(User::factory()->create(), $file);
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionVisible('publishWithFiles')
        ->callAction('publishWithFiles')
        ->assertNotified();

    $fresh = $recipe->fresh();

    expect($fresh->status)->toBe(RecipeStatus::Published)
        ->and($fresh->reviewed_by)->toBe($this->orga->id)
        ->and($fresh->in_lineup)->toBeTrue()
        ->and($file->fresh()->visibility)->toBe(FileVisibility::Approved)
        ->and($file->fresh()->reviewed_by)->toBe($this->orga->id);

    Event::assertDispatchedTimes(RecipePublished::class, 1);
});

it('names the file in the confirmation text of the combined action', function () {
    $file = publishRecipePendingFile('tiny-racer.zip');
    $recipe = publishRecipeContribution(User::factory()->create(), $file);
    $this->actingAs($this->orga);

    $component = Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->mountAction('publishWithFiles');

    $description = $component->instance()->getMountedAction()->getModalDescription();

    expect((string) $description)->toContain('tiny-racer.zip')
        ->and((string) $description)->toContain(__('game_recipes.actions.publish_with_files_confirm'))
        // The same confirmation also says what will run on the players' PCs.
        ->and((string) $description)->toContain(__('game_recipes.release.heading'))
        ->and((string) $description)->toContain(__('game_recipes.release.runs_on_players'));
});

it('hides the combined action while nothing waits for approval', function () {
    $recipe = GameRecipe::factory()->draft()->create();
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionHidden('publishWithFiles');
});

it('shows the combined action but disables it when a pinned file was rejected', function () {
    $rejected = SharedFile::factory()->rejected()->create(['original_name' => 'gone.zip']);
    $recipe = publishRecipeContribution(User::factory()->create(), $rejected);
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionVisible('publishWithFiles')
        ->assertActionDisabled('publishWithFiles');

    expect($recipe->fresh()->status)->toBe(RecipeStatus::Draft);
});

it('saves the lineup toggle for orga', function () {
    $recipe = GameRecipe::factory()->published()->create(['in_lineup' => false]);
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['in_lineup' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipe->fresh()->in_lineup)->toBeTrue();

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['in_lineup' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipe->fresh()->in_lineup)->toBeFalse();
});

it('treats a form save into published like the publish action, once', function () {
    Event::fake([RecipePublished::class]);
    $recipe = publishRecipeContribution(User::factory()->create());
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['status' => RecipeStatus::Published->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $recipe->fresh();

    expect($fresh->status)->toBe(RecipeStatus::Published)
        ->and($fresh->reviewed_by)->toBe($this->orga->id)
        ->and($fresh->in_lineup)->toBeTrue();

    Event::assertDispatchedTimes(RecipePublished::class, 1);

    // Saving the now published recipe again is not another release.
    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['summary' => 'Nochmal gespeichert.'])
        ->call('save')
        ->assertHasNoFormErrors();

    Event::assertDispatchedTimes(RecipePublished::class, 1);
});

it('keeps the lineup flag of a non-contribution published through the form', function () {
    $recipe = GameRecipe::factory()->draft()->create(['in_lineup' => false]);
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->fillForm(['status' => RecipeStatus::Published->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipe->fresh()->status)->toBe(RecipeStatus::Published)
        ->and($recipe->fresh()->in_lineup)->toBeFalse();
});

it('announces a recipe that is created as published', function () {
    Event::fake([RecipePublished::class]);
    $this->actingAs($this->orga);

    Livewire::test(CreateRecipe::class)
        ->fillForm([
            'slug' => 'quake-3',
            'title' => 'Quake III Arena',
            'status' => RecipeStatus::Published->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Event::assertDispatchedTimes(RecipePublished::class, 1);
});

it('keeps the page orga-only and the review ability on the policy', function () {
    $recipe = publishRecipeContribution(User::factory()->create());
    $participant = User::factory()->create();
    $helper = User::factory()->helper()->create();

    foreach ([$participant, $helper] as $user) {
        $this->actingAs($user)
            ->get('/admin/recipes/'.$recipe->id.'/edit')
            ->assertForbidden();

        expect($user->can('review', $recipe))->toBeFalse();
    }

    expect($this->orga->can('review', $recipe))->toBeTrue();
});

it('shows the pull request action only while the repository is configured', function () {
    $recipe = GameRecipe::factory()->draft()->create();
    $this->actingAs($this->orga);

    // The default binding is the null repository: nothing can be proposed.
    expect(app(RecipeRepository::class)->isConfigured())->toBeFalse();

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionHidden('proposePullRequest');

    fakeRecipeRepository();

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->assertActionVisible('proposePullRequest');
});

it('queues the pull request for the recipe the orga asked for', function () {
    Bus::fake([ProposeRecipePullRequestJob::class]);
    fakeRecipeRepository();
    $recipe = GameRecipe::factory()->draft()->create();
    $this->actingAs($this->orga);

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->callAction('proposePullRequest')
        ->assertNotified(__('game_recipes.actions.propose_requested'));

    Bus::assertDispatched(
        ProposeRecipePullRequestJob::class,
        fn (ProposeRecipePullRequestJob $job): bool => $job->recipeId === $recipe->id,
    );
});

it('makes a published contribution visible on the games page under a curated lineup', function () {
    Config::set('launcher.lineup', 'unreal-tournament-2004');
    $file = publishRecipePendingFile();
    $recipe = publishRecipeContribution(User::factory()->create(), $file);
    $participant = User::factory()->create();

    // Before the release the shared game is neither in the lineup nor on /games.
    expect(app(LauncherLineup::class)->allows($recipe->slug))->toBeFalse();

    $this->actingAs($this->orga)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('games', 0));

    Livewire::test(EditRecipe::class, ['record' => $recipe->getRouteKey()])
        ->callAction('publishWithFiles')
        ->assertNotified();

    expect(app(LauncherLineup::class)->allows($recipe->slug))->toBeTrue();

    $this->actingAs($participant)
        ->get('/games')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Games/Index')
            ->has('games', 1)
            ->where('games.0.slug', $recipe->slug)
            ->where('games.0.title', 'Tiny Racer'));
});
