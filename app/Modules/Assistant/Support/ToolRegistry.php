<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Models\User;
use App\Modules\Assistant\Tools\AssistantTool;
use App\Modules\Assistant\Tools\Read\EventsListTool;
use App\Modules\Assistant\Tools\Read\GamesListTool;
use App\Modules\Assistant\Tools\Read\ServerLogsTool;
use App\Modules\Assistant\Tools\Read\ServerSettingsGetTool;
use App\Modules\Assistant\Tools\Read\ServersListTool;
use App\Modules\Assistant\Tools\Read\TournamentGetTool;
use App\Modules\Assistant\Tools\Read\TournamentsListTool;
use App\Modules\Assistant\Tools\Write\TournamentAdvanceTool;
use App\Modules\Assistant\Tools\Write\TournamentCreateTool;
use App\Modules\Assistant\Tools\Write\TournamentUpdateTool;
use App\Support\Modules\Modules;

/**
 * The tools an actor gets: nothing for a non-orga, otherwise every read and
 * write tool whose wrapped modules are enabled. New roles (participants) and
 * new modules (catering, ...) plug in by adding classes to the lists here.
 */
final class ToolRegistry
{
    /** @var list<class-string<AssistantTool>> */
    public const array READ_TOOLS = [
        EventsListTool::class,
        GamesListTool::class,
        TournamentsListTool::class,
        TournamentGetTool::class,
        ServersListTool::class,
        ServerSettingsGetTool::class,
        ServerLogsTool::class,
    ];

    /** @var list<class-string<AssistantTool>> */
    public const array WRITE_TOOLS = [
        TournamentCreateTool::class,
        TournamentUpdateTool::class,
        TournamentAdvanceTool::class,
    ];

    /** @return list<class-string<AssistantTool>> */
    public static function classes(): array
    {
        return [...self::READ_TOOLS, ...self::WRITE_TOOLS];
    }

    /** @return list<AssistantTool> */
    public static function forUser(User $user): array
    {
        if (! $user->isOrga()) {
            return [];
        }

        $modules = app(Modules::class);
        $tools = [];

        foreach (self::classes() as $class) {
            foreach ($class::requiredModules() as $module) {
                if (! $modules->enabled($module)) {
                    continue 2;
                }
            }

            $tools[] = new $class($user);
        }

        return $tools;
    }
}
