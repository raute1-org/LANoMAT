<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Recipes are orga-curated reference data. Participants may propose a draft
 * (through the MCP tool, which calls ContributeRecipe), edit their own draft
 * while it is unreviewed, and read published recipes; everything else is orga.
 */
it('lets a participant create a draft but not browse the recipe library', function () {
    $participant = User::factory()->create();

    expect($participant->can('create', GameRecipe::class))->toBeTrue()
        ->and($participant->can('viewAny', GameRecipe::class))->toBeFalse();
});

it('shows a participant only published recipes', function () {
    $participant = User::factory()->create();
    $published = GameRecipe::factory()->published()->create();
    $draft = GameRecipe::factory()->draft()->create();
    $archived = GameRecipe::factory()->archived()->create();

    expect($participant->can('view', $published))->toBeTrue()
        ->and($participant->can('view', $draft))->toBeFalse()
        ->and($participant->can('view', $archived))->toBeFalse();
});

it('lets a contributor edit their own draft until it is published', function () {
    $contributor = User::factory()->create();
    $other = User::factory()->create();

    $ownDraft = GameRecipe::factory()->contributedBy($contributor)->create();
    $ownPublished = GameRecipe::factory()->published()->create(['contributor_user_id' => $contributor->id]);
    $foreignDraft = GameRecipe::factory()->contributedBy($other)->create();

    expect($contributor->can('update', $ownDraft))->toBeTrue()
        ->and($contributor->can('update', $ownPublished))->toBeFalse()
        ->and($contributor->can('update', $foreignDraft))->toBeFalse();
});

it('keeps publishing, reviewing and deleting for orga', function () {
    $orga = User::factory()->orga()->create();
    $recipe = GameRecipe::factory()->draft()->create();

    expect($orga->can('viewAny', GameRecipe::class))->toBeTrue()
        ->and($orga->can('view', $recipe))->toBeTrue()
        ->and($orga->can('update', $recipe))->toBeTrue()
        ->and($orga->can('review', $recipe))->toBeTrue()
        ->and($orga->can('reviewAny', GameRecipe::class))->toBeTrue()
        ->and($orga->can('delete', $recipe))->toBeTrue()
        ->and($orga->can('deleteAny', GameRecipe::class))->toBeTrue();
});

it('keeps contributors from deleting, reviewing or running the review queue', function () {
    $contributor = User::factory()->create();
    $recipe = GameRecipe::factory()->contributedBy($contributor)->create();

    expect($contributor->can('delete', $recipe))->toBeFalse()
        ->and($contributor->can('deleteAny', GameRecipe::class))->toBeFalse()
        ->and($contributor->can('review', $recipe))->toBeFalse()
        ->and($contributor->can('reviewAny', GameRecipe::class))->toBeFalse();
});

it('lets an admin do everything through the gate-before pass', function () {
    $admin = User::factory()->admin()->create();
    $recipe = GameRecipe::factory()->draft()->create();

    expect($admin->can('viewAny', GameRecipe::class))->toBeTrue()
        ->and($admin->can('update', $recipe))->toBeTrue()
        ->and($admin->can('review', $recipe))->toBeTrue()
        ->and($admin->can('reviewAny', GameRecipe::class))->toBeTrue()
        ->and($admin->can('delete', $recipe))->toBeTrue()
        ->and($admin->can('deleteAny', GameRecipe::class))->toBeTrue();
});
