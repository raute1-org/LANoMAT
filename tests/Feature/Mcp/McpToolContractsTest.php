<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Mcp\Server\LanomatServer;
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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every tool that needs no seed data must answer structured content under its
 * documented root key. This is the "no tool returns a bare string" contract.
 */
dataset('tools with structured output', [
    'lanomat_me' => [MeTool::class, 'user'],
    'events_list' => [EventsListTool::class, 'events'],
    'teams_list' => [TeamsListTool::class, 'teams'],
    'games_search' => [GamesSearchTool::class, 'games'],
    'servers_list' => [ServersListTool::class, 'servers'],
    'recipes_list' => [RecipesListTool::class, 'recipes'],
]);

/**
 * A foreign or unknown id must be a readable tool error ("Not found."), never
 * a stack trace and never an empty response. Tools whose input is not an id
 * carry the expected readable fragment of their validator instead.
 */
dataset('tools with foreign ids', [
    'event_get' => [EventGetTool::class, ['slug' => 'no-such-event'], ['Not found']],
    'event_register' => [EventRegisterTool::class, ['slug' => 'no-such-event'], ['Not found']],
    'tournament_list' => [TournamentListTool::class, ['event_slug' => 'no-such-event'], ['Not found']],
    'tournament_bracket' => [TournamentBracketTool::class, ['tournament_id' => 999_999], ['Not found']],
    'tournament_checkin' => [TournamentCheckinTool::class, ['tournament_id' => 999_999], ['Not found']],
    'match_result' => [MatchResultTool::class, ['match_id' => 999_999, 'action' => 'report', 'score1' => 2, 'score2' => 1], ['Not found']],
    'files_list' => [FilesListTool::class, ['event_slug' => 'no-such-event'], ['Not found']],
    'file_download_url' => [FileDownloadTool::class, ['file_id' => 999_999], ['Not found']],
    'schedule_list' => [ScheduleListTool::class, ['event_slug' => 'no-such-event'], ['Not found']],
    'polls_list' => [PollsListTool::class, ['event_slug' => 'no-such-event'], ['Not found']],
    'poll_vote' => [PollVoteTool::class, ['poll_id' => 999_999, 'option_id' => 1], ['Not found']],
    'catering_menu' => [CateringMenuTool::class, ['event_slug' => 'no-such-event'], ['Not found']],
    'lfg_list' => [LfgListTool::class, ['event_slug' => 'no-such-event'], ['Not found']],
    'lfg_create' => [LfgCreateTool::class, ['event_slug' => 'no-such-event', 'title' => 'Need one', 'game' => 'CS2'], ['Not found']],
    'recipe_get' => [RecipeGetTool::class, ['slug' => 'no-such-recipe'], ['Not found']],
    'recipe_install_plan' => [RecipeInstallPlanTool::class, ['slug' => 'no-such-recipe'], ['Not found']],
    'recipe_avatars' => [RecipeAvatarsTool::class, ['slug' => 'no-such-recipe'], ['Not found']],
    'recipe_contribute' => [RecipeContributeTool::class, ['slug' => 'Bad Slug', 'title' => ''], ['slug must be lowercase-kebab-case']],
]);

it('returns structured content for tools that need no seed data', function (string $toolClass, string $rootKey) {
    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool($toolClass)
        ->assertOk()
        ->assertStructuredContentPath($rootKey, fn ($value): bool => is_array($value));
})->with('tools with structured output');

it('answers a foreign id with a readable error instead of a crash', function (string $toolClass, array $arguments, array $expectedErrors) {
    LanomatServer::actingAs(User::factory()->create());

    LanomatServer::tool($toolClass, $arguments)->assertHasErrors($expectedErrors);
})->with('tools with foreign ids');

it('refuses a call without an authenticated user with a readable error', function () {
    // No actingAs(): the tools fall back to the guard, which is empty.
    LanomatServer::tool(MeTool::class)->assertHasErrors(['Unauthenticated']);
});
