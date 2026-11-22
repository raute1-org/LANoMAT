<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Resources\ImportMenuSkillResource;
use App\Modules\Mcp\Resources\RecipeContributeSkillResource;
use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Support\McpCodes;
use App\Modules\Mcp\Tools\RecipeContributeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('launcher.lineup', '');
});

function contributionCatalogToken(User $user): string
{
    $token = McpCodes::bearerToken();

    McpToken::query()->forceCreate([
        'user_id' => $user->id,
        'name' => 'contribution catalog test',
        'token_hash' => McpCodes::hash($token),
        'abilities' => null,
        'last_used_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    return $token;
}

/**
 * The smallest contribution the tool accepts: one required base archive and a
 * single step. Its text is far from what the catalogue takes.
 *
 * @return array<string, mixed>
 */
function contributionCatalogBarePayload(string $slug = 'bare-racer'): array
{
    return [
        'slug' => $slug,
        'title' => 'Bare Racer',
        'summary' => 'A game contributed without a written skill.',
        'platforms' => ['windows'],
        'requires' => [[
            'role' => 'base',
            'label' => 'Bare Racer archive',
            'match' => ['bare-racer.zip'],
            'required' => true,
        ]],
        'steps' => [[
            'id' => 'unpack',
            'title' => 'Unpack the archive',
            'os' => 'windows',
            'optional' => false,
            'detail' => 'Unpack the archive into the target folder.',
        ]],
    ];
}

it('answers a bare contribution with what the catalogue would refuse', function () {
    $fake = fakeRecipeRepository();

    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(RecipeContributeTool::class, contributionCatalogBarePayload())
        ->assertOk()
        ->assertStructuredContentPath('catalog', fn (mixed $catalog): bool => is_array($catalog)
            && array_keys($catalog) === ['pull_requests_enabled', 'problems', 'problems_when_published'])
        ->assertStructuredContentPath('catalog.pull_requests_enabled', true)
        ->assertStructuredContentPath('catalog.problems', fn (mixed $problems): bool => is_array($problems)
            && collect($problems)->contains(fn (string $problem): bool => str_contains($problem, 'SKILL.md has'))
            && collect($problems)->contains(fn (string $problem): bool => str_contains($problem, '3 to 6 steps'))
            && collect($problems)->contains(fn (string $problem): bool => str_contains($problem, 'needs verification on the LAN')))
        ->assertStructuredContentPath('catalog.problems_when_published', fn (mixed $problems): bool => is_array($problems)
            && collect($problems)->contains(fn (string $problem): bool => str_contains($problem, 'SKILL.md has'))
            && ! collect($problems)->contains(fn (string $problem): bool => str_contains($problem, '3 to 6 steps')))
        // A draft-only rule is not a reason to keep a published recipe out.
        ->assertSee('The catalogue would refuse it')
        ->assertSee('SKILL.md has');

    // Asking is free: no request, no state.
    $recipe = GameRecipe::query()->where('slug', 'bare-racer')->firstOrFail();

    expect($recipe->pr_state)->toBeNull()
        ->and($recipe->pr_number)->toBeNull();

    $fake->assertNothingProposed();
});

it('takes a skill_markdown and the catalogue problems shrink to the step rules', function () {
    fakeRecipeRepository();

    $payload = contributionCatalogBarePayload('half-racer');
    $payload['skill_markdown'] = RecipeContributeSkillResource::skillSkeleton('half-racer', 'Half Racer');

    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(RecipeContributeTool::class, $payload)
        ->assertOk()
        ->assertStructuredContentPath('catalog.problems', fn (mixed $problems): bool => is_array($problems)
            && ! collect($problems)->contains(fn (string $problem): bool => str_contains($problem, 'SKILL.md has'))
            && collect($problems)->contains(fn (string $problem): bool => str_contains($problem, '3 to 6 steps')));

    $recipe = GameRecipe::query()->where('slug', 'half-racer')->firstOrFail();

    expect($recipe->skill_markdown)->toStartWith("---\nname: half-racer\n")
        ->and($recipe->skill_markdown)->toContain('name: half-racer');
});

it('answers a contribution that follows the how-to with no catalogue problems at all', function () {
    fakeRecipeRepository();

    $payload = RecipeContributeSkillResource::payloadExample('example-racer', 'Example Racer');
    $payload['skill_markdown'] = RecipeContributeSkillResource::skillSkeleton('example-racer', 'Example Racer');

    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(RecipeContributeTool::class, $payload)
        ->assertOk()
        ->assertStructuredContentPath('catalog.problems', [])
        ->assertStructuredContentPath('catalog.problems_when_published', [])
        ->assertSee('ready for a pull request');

    $recipe = GameRecipe::query()->where('slug', 'example-racer')->firstOrFail();

    expect($recipe->status->value)->toBe('draft')
        ->and($recipe->skill_markdown)->toBe($payload['skill_markdown']);
});

it('reports pull requests as off while no repository is configured', function () {
    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(RecipeContributeTool::class, contributionCatalogBarePayload('off-racer'))
        ->assertOk()
        ->assertStructuredContentPath('catalog.pull_requests_enabled', false)
        ->assertStructuredContentPath('catalog.problems', fn (mixed $problems): bool => is_array($problems) && $problems !== []);
});

it('returns the same catalogue object from the REST contribution', function () {
    $fake = fakeRecipeRepository();
    $user = User::factory()->create();

    $response = $this->withToken(contributionCatalogToken($user))
        ->postJson('/api/v1/recipes', contributionCatalogBarePayload('rest-racer'))
        ->assertCreated()
        ->assertJsonStructure([
            'recipe' => ['slug', 'title', 'status', 'launcher_supported', 'pull_request'],
            'catalog' => ['pull_requests_enabled', 'problems', 'problems_when_published'],
        ])
        ->assertJsonPath('catalog.pull_requests_enabled', true)
        ->assertJsonPath('recipe.status', 'draft')
        ->assertJsonCount(3, 'catalog');

    $problems = $response->json('catalog.problems');
    $published = $response->json('catalog.problems_when_published');

    expect($problems)->toBeArray()
        ->and(implode("\n", $problems))->toContain('SKILL.md has')
        ->and(implode("\n", $problems))->toContain('3 to 6 steps')
        ->and(implode("\n", $published))->toContain('SKILL.md has')
        ->and(implode("\n", $published))->not->toContain('3 to 6 steps');

    $recipe = GameRecipe::query()->where('slug', 'rest-racer')->firstOrFail();

    expect($recipe->pr_state)->toBeNull();

    $fake->assertNothingProposed();
});

it('answers an orga and a participant with the same catalogue keys', function () {
    fakeRecipeRepository();

    LanomatServer::actingAs(User::factory()->create());
    LanomatServer::tool(RecipeContributeTool::class, contributionCatalogBarePayload('participant-racer'))
        ->assertOk()
        ->assertStructuredContentPath('catalog.pull_requests_enabled', true)
        ->assertStructuredContentPath('catalog.problems', fn (mixed $problems): bool => is_array($problems));

    LanomatServer::actingAs(User::factory()->create(['role' => Role::Orga]));
    LanomatServer::tool(RecipeContributeTool::class, contributionCatalogBarePayload('orga-racer'))
        ->assertOk()
        ->assertStructuredContentPath('catalog.pull_requests_enabled', true)
        ->assertStructuredContentPath('catalog.problems', fn (mixed $problems): bool => is_array($problems));
});

it('refuses control characters in skill_markdown and creates nothing', function () {
    fakeRecipeRepository();

    $payload = contributionCatalogBarePayload('control-racer');
    // A bell character in the middle: the HTTP stack trims control characters
    // at the end of a string, one inside survives to the validator.
    $payload['skill_markdown'] = "---\nname: control-racer\n\x07description: \"Install Control Racer.\"\n---\n";

    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool(RecipeContributeTool::class, $payload)
        ->assertHasErrors(['skill_markdown']);

    expect(GameRecipe::query()->count())->toBe(0);

    $this->withToken(contributionCatalogToken(User::factory()->create()))
        ->postJson('/api/v1/recipes', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('skill_markdown');

    expect(GameRecipe::query()->count())->toBe(0);
});

it('leaves a refused contribution without a catalogue key', function () {
    $this->withToken(contributionCatalogToken(User::factory()->create()))
        ->postJson('/api/v1/recipes', ['slug' => 'no-title'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title')
        ->assertJsonMissingPath('catalog');

    expect(GameRecipe::query()->count())->toBe(0);
});

it('answers a recipe that fails the schema check with its problems, not a server error', function () {
    $payload = contributionCatalogBarePayload('schema-racer');
    unset($payload['steps']);

    $this->withToken(contributionCatalogToken(User::factory()->create()))
        ->postJson('/api/v1/recipes', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipe')
        ->assertJsonMissingPath('catalog');

    expect(GameRecipe::query()->count())->toBe(0);
});

it('advertises skill_markdown in the recipe_contribute schema', function () {
    $token = contributionCatalogToken(User::factory()->create());

    $json = $this->withHeaders([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ])->assertOk()->json();

    $tools = $json['result']['tools'];

    // The count stays 29: the how-to is a resource, not another tool.
    expect($tools)->toHaveCount(29);

    $contribute = collect($tools)->firstWhere('name', 'recipe_contribute');
    $property = $contribute['inputSchema']['properties']['skill_markdown'] ?? null;

    expect($property)->toBeArray()
        ->and($property['type'])->toBe('string')
        ->and($property['description'])->toContain('60 to 150')
        ->and($property['description'])->toContain('lanomat://skills/contribute-recipe');
});

it('serves the contribute how-to as a resource and lists it', function () {
    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::resources()->assertRegistered([
        ImportMenuSkillResource::class,
        RecipeContributeSkillResource::class,
    ]);

    $howTo = RecipeContributeSkillResource::markdown();
    $skeleton = RecipeContributeSkillResource::skillSkeleton('example-racer', 'Example Racer');

    // The skeleton the assistant copies is the one the test contributes with,
    // and the catalogue counts lines exactly this way (split on newlines,
    // including the trailing empty line).
    expect($howTo)->toContain($skeleton)
        ->and(count(explode("\n", rtrim($skeleton, "\r\n")."\n")))->toBeGreaterThanOrEqual(60)
        ->and(count(explode("\n", rtrim($skeleton, "\r\n")."\n")))->toBeLessThanOrEqual(150);

    LanomatServer::resource(RecipeContributeSkillResource::class)
        ->assertSee('recipe_contribute')
        ->assertSee('60')
        ->assertSee('150')
        ->assertSee('3 to 6')
        ->assertSee('shared_file_id')
        ->assertSee('RECIPES_MAX_DRAFTS_PER_USER')
        ->assertSee('problems_when_published')
        ->assertSee('catalog');
});

it('tells the assistant that a second call replaces the draft', function () {
    LanomatServer::actingAs(User::factory()->create());

    // A second call that leaves fields out clears them: that is the contract,
    // and both the answer and the how-to have to say so.
    LanomatServer::tool(RecipeContributeTool::class, contributionCatalogBarePayload('replace-racer'))
        ->assertOk()
        ->assertSee('COMPLETE recipe');

    expect(RecipeContributeSkillResource::markdown())->toContain('a call replaces the');
});
