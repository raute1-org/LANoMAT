<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Presence\Contracts\GameServerDirectory;

/** Bound while the GameServers module (or the native client layer that reads it) is off. */
final class NullGameServerDirectory implements GameServerDirectory
{
    public function rosters(): array
    {
        return [];
    }

    public function visibleServers(User $viewer, Event $event): array
    {
        return [];
    }
}
