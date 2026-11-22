<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Contracts\ServerOccupants;

/** Bound while the Presence module is off: nobody is located, so no row says who is on it. */
final class NullServerOccupants implements ServerOccupants
{
    public function forServer(int $linkId, Event $event, User $viewer): ?array
    {
        return null;
    }
}
