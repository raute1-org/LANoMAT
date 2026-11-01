<?php

declare(strict_types=1);

namespace App\Modules\Presence\Contracts;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Presence\Domain\ServerRoster;

/**
 * What the presence read model needs to know about LANoMAT's own game servers. The GameServers
 * module is optional: while it is off, a null implementation answers "no servers".
 */
interface GameServerDirectory
{
    /**
     * Every server that answers (or is starting), with the player names of its last query.
     *
     * @return list<ServerRoster>
     */
    public function rosters(): array;

    /**
     * The servers this viewer may see for this event, keyed by server link id, in the shape the
     * native servers API shows them (`joinUrl` and `copyText` exactly as built there).
     *
     * @return array<int, array{title: ?string, address: ?string, port: ?int, joinUrl: ?string, copyText: ?string}>
     */
    public function visibleServers(User $viewer, Event $event): array;
}
