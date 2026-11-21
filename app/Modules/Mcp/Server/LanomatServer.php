<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Server;

use App\Modules\Mcp\Prompts\ImportMenuPrompt;
use App\Modules\Mcp\Prompts\InstallGamePrompt;
use App\Modules\Mcp\Resources\ImportMenuSkillResource;
use App\Modules\Mcp\Resources\RecipeSkillResource;
use App\Modules\Mcp\Tools\CateringMenuGetTool;
use App\Modules\Mcp\Tools\CateringMenuImportTool;
use App\Modules\Mcp\Tools\CateringMenuPreviewTool;
use App\Modules\Mcp\Server\Methods\ListTools as LanomatListTools;
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
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('LANoMAT')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
You are connected to a LANoMAT LAN-party server.

Start with lanomat_me to see who you are and which modules are enabled.
Events are addressed by slug; tournaments and matches by numeric id.

For "install <game> for me": call recipes_list (the user's words are enough —
"ut2004", "ut 2k4", "unreal tournament" all match unreal-tournament-2004), then
recipe_get, then recipe_install_plan with the user's OS. Prefer files whose
source is "lan" (the LANoMAT file host on the local network); ask before any
source "external" download. Avatars are a multi-select from recipe_avatars;
download only the chosen ones. The base game is required, the UT2004VR mod is
an optional step.

For "import this menu" (orga/admin only): follow the import-menu prompt — read the
PDF/page yourself, catering_menu_preview, show the human, then catering_menu_import.

Never invent ids or prices. Everything you need comes from the tools.
TEXT)]
class LanomatServer extends Server
{
    /**
     * The full catalogue is 24 tools today; one page is enough for every
     * ordinary MCP client, including one that does not follow cursors. The
     * package maximum stays 50, so `per_page` still has room and the doctor
     * still follows a cursor when a future profile outgrows one page.
     */
    public int $defaultPaginationLength = 50;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
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
        FilesListTool::class,
        FileDownloadTool::class,
        ServersListTool::class,
        ScheduleListTool::class,
        PollsListTool::class,
        PollVoteTool::class,
        CateringMenuTool::class,
        CateringMenuGetTool::class,
        CateringMenuPreviewTool::class,
        CateringMenuImportTool::class,
        LfgListTool::class,
        LfgCreateTool::class,
        RecipesListTool::class,
        RecipeGetTool::class,
        RecipeInstallPlanTool::class,
        RecipeAvatarsTool::class,
        RecipeContributeTool::class,
    ];

    /** @var array<int, class-string<Server\Resource>> */
    protected array $resources = [
        RecipeSkillResource::class,
        ImportMenuSkillResource::class,
    ];

    /** @var array<int, class-string<Prompt>> */
    protected array $prompts = [
        InstallGamePrompt::class,
        ImportMenuPrompt::class,
    ];

    /**
     * Use LANoMAT's cursor handling for `tools/list` (see the class docblock):
     * unpadded base64url cursors work, a malformed cursor is -32602 instead of
     * a silent replay of page 1, and the last page carries `nextCursor: null`.
     */
    protected function boot(): void
    {
        $this->addMethod('tools/list', LanomatListTools::class);
    }
}
