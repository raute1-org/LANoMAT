<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Enums\ConsoleKind;

/**
 * Suggestions for the console field, per console tool. They are plain command prefixes
 * that the user completes and sends like any typed command, so they go through the same
 * validation and escaping: a quick command is no separate, trusted path.
 */
final class RemoteQuickCommands
{
    /** Commands of an itzg/minecraft-server console. A trailing space means an argument follows. */
    private const MINECRAFT = ['list', 'tps', 'save-all', 'whitelist add ', 'whitelist remove ', 'op ', 'deop ', 'say '];

    /**
     * @return list<string>
     */
    public static function forConsole(?ConsoleKind $console): array
    {
        return match ($console) {
            ConsoleKind::RconCli => self::MINECRAFT,
            null => [],
        };
    }
}
