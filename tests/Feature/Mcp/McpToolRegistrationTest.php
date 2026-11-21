<?php

declare(strict_types=1);

use App\Modules\Mcp\Server\LanomatServer;
use App\Modules\Mcp\Tools\CateringMenuGetTool;
use App\Modules\Mcp\Tools\CateringMenuImportTool;
use App\Modules\Mcp\Tools\CateringMenuPreviewTool;
use App\Modules\Mcp\Tools\CateringMenuTool;
use App\Modules\Mcp\Tools\EventGetTool;
use App\Modules\Mcp\Tools\EventRegisterTool;
use App\Modules\Mcp\Tools\EventsListTool;
use App\Modules\Mcp\Tools\FileDownloadTool;
use App\Modules\Mcp\Tools\FilesListTool;
use App\Modules\Mcp\Tools\GamesSearchTool;
use App\Modules\Mcp\Tools\LfgCreateTool;
use App\Modules\Mcp\Tools\LfgListTool;
use App\Modules\Mcp\Tools\MatchResultTool;
use App\Modules\Mcp\Tools\MeTool;
use App\Modules\Mcp\Tools\PollsListTool;
use App\Modules\Mcp\Tools\PollVoteTool;
use App\Modules\Mcp\Tools\RecipeAvatarsTool;
use App\Modules\Mcp\Tools\RecipeContributeTool;
use App\Modules\Mcp\Tools\RecipeGetTool;
use App\Modules\Mcp\Tools\RecipeInstallPlanTool;
use App\Modules\Mcp\Tools\RecipesListTool;
use App\Modules\Mcp\Tools\ScheduleListTool;
use App\Modules\Mcp\Tools\ServersListTool;
use App\Modules\Mcp\Tools\TeamsListTool;
use App\Modules\Mcp\Tools\TournamentBracketTool;
use App\Modules\Mcp\Tools\TournamentCheckinTool;
use App\Modules\Mcp\Tools\TournamentListTool;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request as McpRequest;

uses(RefreshDatabase::class);

/**
 * Tools that must be present in every profile: their module is core, or they
 * read only core aggregates.
 *
 * @return list<class-string>
 */
function mcpCoreToolClasses(): array
{
    return [
        MeTool::class,
        EventsListTool::class,
        EventGetTool::class,
        EventRegisterTool::class,
        TeamsListTool::class,
        TournamentListTool::class,
        TournamentBracketTool::class,
        TournamentCheckinTool::class,
        MatchResultTool::class,
        GamesSearchTool::class,
    ];
}

/** @return array<class-string, Module> tool class => the one module that gates it */
function mcpOptionalToolModules(): array
{
    return [
        FilesListTool::class => Module::Files,
        FileDownloadTool::class => Module::Files,
        ServersListTool::class => Module::GameServers,
        ScheduleListTool::class => Module::Schedule,
        PollsListTool::class => Module::Voting,
        PollVoteTool::class => Module::Voting,
        CateringMenuTool::class => Module::Catering,
        CateringMenuGetTool::class => Module::Catering,
        CateringMenuPreviewTool::class => Module::Catering,
        CateringMenuImportTool::class => Module::Catering,
        LfgListTool::class => Module::Lfg,
        LfgCreateTool::class => Module::Lfg,
        RecipesListTool::class => Module::GameRecipes,
        RecipeGetTool::class => Module::GameRecipes,
        RecipeInstallPlanTool::class => Module::GameRecipes,
        RecipeAvatarsTool::class => Module::GameRecipes,
        RecipeContributeTool::class => Module::GameRecipes,
    ];
}

/** Bind a fresh module configuration into the container (the tool registry reads it live). */
function mcpUseModules(string $profile, array $overrides = []): void
{
    app()->instance(Modules::class, new Modules($profile, $overrides));
}

/**
 * Overrides that switch a module off together with every module that requires
 * it (recursively) — the Modules constructor refuses an enabled module whose
 * dependency is off.
 *
 * @return array<string, bool>
 */
function mcpDisableWithDependents(Module $module): array
{
    $overrides = [$module->key() => false];

    do {
        $changed = false;

        foreach (Module::cases() as $candidate) {
            if (array_key_exists($candidate->key(), $overrides)) {
                continue;
            }

            foreach ($candidate->requires() as $required) {
                if (array_key_exists($required->key(), $overrides)) {
                    $overrides[$candidate->key()] = false;
                    $changed = true;

                    break;
                }
            }
        }
    } while ($changed);

    return $overrides;
}

dataset('optional tool modules', fn (): array => collect(mcpOptionalToolModules())
    ->map(static fn (Module $module, string $toolClass): array => [$toolClass, $module])
    ->values()
    ->all());

it('registers every tool under the full profile', function () {
    mcpUseModules('full');

    LanomatServer::tools()->assertRegistered([
        ...mcpCoreToolClasses(),
        ...array_keys(mcpOptionalToolModules()),
    ]);
});

it('registers only core tools under the core profile', function () {
    mcpUseModules('core');

    $tools = LanomatServer::tools();
    $tools->assertRegistered(mcpCoreToolClasses());

    foreach (array_keys(mcpOptionalToolModules()) as $optionalTool) {
        $tools->assertNotRegistered($optionalTool);
    }
});

it('gates each optional tool on exactly its own module', function (string $toolClass, Module $module) {
    // Full profile with this one optional module (and its dependents) off.
    mcpUseModules('full', mcpDisableWithDependents($module));

    expect(app($toolClass)->shouldRegister(new McpRequest))->toBeFalse();

    // ... and the registry agrees with the tool.
    LanomatServer::tools()->assertNotRegistered($toolClass);

    mcpUseModules('full');

    expect(app($toolClass)->shouldRegister(new McpRequest))->toBeTrue();
    LanomatServer::tools()->assertRegistered($toolClass);
})->with('optional tool modules');

it('never gates a core tool on an optional module', function () {
    foreach (mcpOptionalToolModules() as $module) {
        mcpUseModules('full', mcpDisableWithDependents($module));

        LanomatServer::tools()->assertRegistered(mcpCoreToolClasses());
    }
});
