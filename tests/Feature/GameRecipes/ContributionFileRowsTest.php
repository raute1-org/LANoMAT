<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Actions\ContributeRecipe;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Models\GameRecipeInstall;
use App\Modules\GameRecipes\Support\BuildInstallPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A contribution's file rows are what install plans point at: `recipe_file_id`
 * is handed to the launcher and stored inside `game_recipe_installs.plan`, so an
 * edit must update the rows in place instead of deleting and recreating them.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function contributionRowsPayload(array $overrides = []): array
{
    return [
        'slug' => 'lan-party-blitz',
        'title' => 'LAN Party Blitz',
        'summary' => 'A two-folder racing game from the LAN file host.',
        'platforms' => ['windows'],
        'via' => 'web',
        'requires' => [
            [
                'role' => 'base',
                'label' => 'Blitz (portable archive)',
                'match' => ['blitz.zip'],
            ],
            [
                'role' => 'patch',
                'label' => 'Blitz patch',
                'match' => ['blitz-patch.zip'],
            ],
        ],
        'steps' => [[
            'id' => 'check-install',
            'title' => 'Unpack and check',
            'os' => 'any',
            'optional' => false,
            'detail' => 'Unpack the archive.',
            'verify' => [['type' => 'file_exists', 'path' => '{{target}}/Blitz.exe']],
        ]],
        ...$overrides,
    ];
}

/**
 * The file rows of a recipe by their `role|label` key, in `sort` order.
 *
 * @return array<string, GameRecipeFile>
 */
function contributionFileRows(GameRecipe $recipe): array
{
    return $recipe->files()->get()
        ->mapWithKeys(static fn (GameRecipeFile $file): array => [$file->role->value.'|'.$file->label => $file])
        ->all();
}

it('keeps every file row id when an edit changes only the summary', function () {
    $user = User::factory()->create();
    $contribute = app(ContributeRecipe::class);

    $recipe = $contribute->handle($user, contributionRowsPayload());
    $before = collect(contributionFileRows($recipe))->map(static fn (GameRecipeFile $file): int => $file->id)->all();

    $edited = $contribute->handle($user, contributionRowsPayload(['summary' => 'Now with a better summary.']));
    $after = collect(contributionFileRows($edited))->map(static fn (GameRecipeFile $file): int => $file->id)->all();

    expect($edited->summary)->toBe('Now with a better summary.')
        ->and($after)->toBe($before)
        // No row was recreated behind the kept ids.
        ->and(GameRecipeFile::query()->where('game_recipe_id', $edited->id)->count())->toBe(2)
        ->and(GameRecipeFile::query()->where('game_recipe_id', $edited->id)->pluck('id')->all())
        ->toEqualCanonicalizing(array_values($before));
});

it('keeps the ids and updates sort when requirements are reordered', function () {
    $user = User::factory()->create();
    $contribute = app(ContributeRecipe::class);

    $recipe = $contribute->handle($user, contributionRowsPayload());
    $base = contributionFileRows($recipe)['base|Blitz (portable archive)'];
    $patch = contributionFileRows($recipe)['patch|Blitz patch'];

    expect($base->sort)->toBe(0)->and($patch->sort)->toBe(1);

    $reordered = $contribute->handle($user, contributionRowsPayload(['requires' => [
        contributionRowsPayload()['requires'][1],
        contributionRowsPayload()['requires'][0],
    ]]));

    $rows = contributionFileRows($reordered);

    expect($rows['patch|Blitz patch']->id)->toBe($patch->id)
        ->and($rows['patch|Blitz patch']->sort)->toBe(0)
        ->and($rows['base|Blitz (portable archive)']->id)->toBe($base->id)
        ->and($rows['base|Blitz (portable archive)']->sort)->toBe(1)
        ->and(GameRecipeFile::query()->where('game_recipe_id', $reordered->id)->count())->toBe(2);
});

it('deletes only the row of a removed requirement', function () {
    $user = User::factory()->create();
    $contribute = app(ContributeRecipe::class);

    $recipe = $contribute->handle($user, contributionRowsPayload());
    $kept = contributionFileRows($recipe)['base|Blitz (portable archive)'];

    $edited = $contribute->handle($user, contributionRowsPayload(['requires' => [
        contributionRowsPayload()['requires'][0],
    ]]));

    expect(GameRecipeFile::query()->where('game_recipe_id', $edited->id)->count())->toBe(1)
        ->and($edited->files()->first()->id)->toBe($kept->id)
        ->and(GameRecipeFile::query()->find($kept->id))->not->toBeNull();
});

it('creates one row when a requirement is added and keeps the existing ones', function () {
    $user = User::factory()->create();
    $contribute = app(ContributeRecipe::class);

    $recipe = $contribute->handle($user, contributionRowsPayload());
    $before = collect(contributionFileRows($recipe))->map(static fn (GameRecipeFile $file): int => $file->id)->all();

    $edited = $contribute->handle($user, contributionRowsPayload(['requires' => [
        ...contributionRowsPayload()['requires'],
        [
            'role' => 'mod',
            'label' => 'Blitz extra cars',
            'match' => ['blitz-cars.zip'],
        ],
    ]]));

    $rows = contributionFileRows($edited);

    expect($rows)->toHaveCount(3)
        ->and($rows['base|Blitz (portable archive)']->id)->toBe($before['base|Blitz (portable archive)'])
        ->and($rows['patch|Blitz patch']->id)->toBe($before['patch|Blitz patch'])
        ->and($rows['mod|Blitz extra cars']->id)->not->toBeNull()
        ->and($rows['mod|Blitz extra cars']->sort)->toBe(2);
});

it('keeps the admin pin, and a payload pin wins for its own requirement', function () {
    $user = User::factory()->create();
    $adminPin = SharedFile::factory()->approved()->create(['original_name' => 'blitz.zip']);
    $ownPin = SharedFile::factory()->create(['user_id' => $user->id, 'original_name' => 'blitz-patch.zip']);
    $contribute = app(ContributeRecipe::class);

    $recipe = $contribute->handle($user, contributionRowsPayload());
    $recipe->files()->where('label', 'Blitz (portable archive)')->firstOrFail()
        ->update(['shared_file_id' => $adminPin->id]);
    $before = collect(contributionFileRows($recipe))->map(static fn (GameRecipeFile $file): int => $file->id)->all();

    $payload = contributionRowsPayload(['summary' => 'Pinned by the contributor.']);
    $payload['requires'][1]['shared_file_id'] = $ownPin->id;

    $edited = $contribute->handle($user, $payload);
    $rows = contributionFileRows($edited);

    expect($rows['base|Blitz (portable archive)']->id)->toBe($before['base|Blitz (portable archive)'])
        ->and($rows['base|Blitz (portable archive)']->shared_file_id)->toBe($adminPin->id)
        ->and($rows['patch|Blitz patch']->id)->toBe($before['patch|Blitz patch'])
        ->and($rows['patch|Blitz patch']->shared_file_id)->toBe($ownPin->id);
});

it('drops an existing pin when the requirement now asks for another file', function () {
    $user = User::factory()->create();
    $adminPin = SharedFile::factory()->approved()->create(['original_name' => 'blitz.zip']);
    $contribute = app(ContributeRecipe::class);

    $recipe = $contribute->handle($user, contributionRowsPayload());
    $recipe->files()->where('label', 'Blitz (portable archive)')->firstOrFail()
        ->update(['shared_file_id' => $adminPin->id]);

    // Same role and label, but the patterns name a different archive: the
    // admin chose a file for the old description, not for this one.
    $payload = contributionRowsPayload();
    $payload['requires'][0]['match'] = ['something-else.zip'];

    $rows = contributionFileRows($contribute->handle($user, $payload));

    expect($rows['base|Blitz (portable archive)']->shared_file_id)->toBeNull();
});

it('keeps an existing pin when only the order or the case of the patterns changes', function () {
    $user = User::factory()->create();
    $adminPin = SharedFile::factory()->approved()->create(['original_name' => 'blitz.zip']);
    $contribute = app(ContributeRecipe::class);

    $payload = contributionRowsPayload();
    $payload['requires'][0]['match'] = ['blitz.zip', 'blitz-*.zip'];
    $recipe = $contribute->handle($user, $payload);
    $recipe->files()->where('label', 'Blitz (portable archive)')->firstOrFail()
        ->update(['shared_file_id' => $adminPin->id]);

    $payload['requires'][0]['match'] = ['BLITZ-*.zip', 'blitz.zip'];

    $rows = contributionFileRows($contribute->handle($user, $payload));

    expect($rows['base|Blitz (portable archive)']->shared_file_id)->toBe($adminPin->id);
});

it('never hands a pin to a renamed or duplicated requirement', function () {
    $user = User::factory()->create();
    $adminPin = SharedFile::factory()->approved()->create(['original_name' => 'blitz.zip']);
    $contribute = app(ContributeRecipe::class);

    $recipe = $contribute->handle($user, contributionRowsPayload());
    $recipe->files()->where('label', 'Blitz (portable archive)')->firstOrFail()
        ->update(['shared_file_id' => $adminPin->id]);

    // The pinned requirement twice, and a third under a new label.
    $payload = contributionRowsPayload();
    $payload['requires'][] = $payload['requires'][0];
    $payload['requires'][] = ['role' => 'base', 'label' => 'Blitz (renamed)', 'match' => ['blitz.zip']];

    $files = $contribute->handle($user, $payload)->files()->orderBy('sort')->get();

    expect($files->where('shared_file_id', $adminPin->id))->toHaveCount(1)
        ->and($files->firstWhere('label', 'Blitz (renamed)')->shared_file_id)->toBeNull();
});

it('leaves a stored install plan resolvable across an edit', function () {
    $user = User::factory()->create();
    $contribute = app(ContributeRecipe::class);

    $recipe = $contribute->handle($user, contributionRowsPayload());
    app(BuildInstallPlan::class)->handle($recipe, $user, 'windows');

    $install = GameRecipeInstall::query()->latest('id')->firstOrFail();
    $planFileIds = collect($install->plan['files'])->pluck('recipe_file_id')->all();

    expect($planFileIds)->not->toBeEmpty();

    $edited = $contribute->handle($user, contributionRowsPayload(['summary' => 'Edited after the plan.']));

    foreach ($planFileIds as $id) {
        $row = GameRecipeFile::query()->find($id);

        expect($row)->not->toBeNull()
            ->and($row->game_recipe_id)->toBe($edited->id);
    }
});
