<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Contracts;

use App\Models\User;
use App\Modules\Events\Models\Event;

/**
 * Who of the LANoMAT accounts is on this game server right now, as one viewer may see them.
 * A server row answers "how many in-game players are there?" itself; this answers the other
 * question — "is anybody I know on it?" — from the Presence read model.
 *
 * GameServers owns the question and never imports Presence; the Presence read model answers
 * through an adapter while both modules are enabled, and a null implementation answers
 * "nobody may see anybody" otherwise.
 *
 * Privacy is Presence's rule (`PresenceVisibility`), not this contract's: somebody who
 * switched `share_location` off and somebody blocked either way with the viewer is neither
 * listed nor counted, so nothing can be inferred about them. The viewer always sees
 * themselves.
 *
 * **The event is the scope of "who".** Presence locates the participants of one event, so
 * every caller names the event it means:
 *  - a server list of one event (the server page, `?event=<slug>`, an event's own links) uses
 *    that event;
 *  - the global browser and the permanent servers use the current event: permanent servers
 *    belong to no event, and the people on them are the people at the LAN that is running
 *    now.
 *
 * The resolver is one instance per web request, so a page of fifty servers reads the
 * location snapshot and computes who is visible once, never per row.
 */
interface ServerOccupants
{
    /**
     * At most eight visible accounts on that server link, by name, and how many further
     * visible accounts there are — or null when this viewer may not see that event's people
     * at all (the audience is the event's people list: its participants and orga).
     *
     * The list can be shorter than the player count the server's own query reports: that
     * count includes people without an account, people the name matching did not find, and
     * people who keep their location to themselves. Nothing here tells those apart.
     *
     * `avatarUrl` is the avatar the website renders for a person (`User::avatar_src`, the
     * local avatar route), never a Discord CDN address.
     *
     * @return array{people: list<array{id: int, name: string, avatarUrl: string|null}>, more: int}|null
     */
    public function forServer(int $linkId, Event $event, User $viewer): ?array;
}
