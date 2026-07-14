<?php

namespace App\Modules\Discord\Interactions;

class CommandDefinitions
{
    /**
     * Global slash command definitions registered via bulk overwrite.
     *
     * Empty for now — Task 17 adds the first slash commands alongside their
     * CommandRouter handlers.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [];
    }
}
