<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Prompts;

use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('install-game')]
#[Description('Install a LAN game from a recipe on the player\'s machine, preferring files already on the LAN file host.')]
class InstallGamePrompt extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'game',
                description: 'What the user asked for, e.g. "ut2004" or "unreal tournament".',
                required: true,
            ),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return app(Modules::class)->enabled(Module::GameRecipes);
    }

    /** @return array<int, Response> */
    public function handle(Request $request): array
    {
        $game = (string) $request->get('game');

        return [
            Response::text(<<<PROMPT
                Install "{$game}" on this machine using LANoMAT.

                1. Call recipes_list with the user's words; if several match, ask one short question.
                2. Call recipe_get for the chosen slug and read the requirements.
                3. Call recipe_install_plan with the user's OS (windows/linux) and, when known, the event slug.
                4. Show the plan in one compact block: LAN files, missing files, optional steps.
                5. If avatars exist, offer a multi-select and download only the chosen ones — from the LAN URL.
                6. Execute the steps in order; skip optional steps unless the user agreed. Verify after each step.
                7. On failure, quote the failing command and the LANoMAT error; ask before retrying.
                Never download a file marked source=external without asking.
                PROMPT),
        ];
    }
}
