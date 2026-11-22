<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Contracts\ServerOccupants;
use App\Modules\Presence\Support\LocationSnapshot;
use App\Modules\Presence\Support\PresenceVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The Presence read model's answer to a server row's "who is on it?".
 *
 * It lives here because this is the one place that may name both optional modules: WebVoice
 * already carries the other direction of the same bridge (the native client's
 * `NativeGameServerDirectory` answers Presence's `GameServerDirectory` from the GameServers
 * projections). GameServers owns the contract, Presence owns whom a viewer may see, and
 * neither imports the other. Bound only while GameServers and Presence are on; otherwise
 * GameServers' `NullServerOccupants` answers.
 *
 * One instance per web request: the location snapshot is read once and the visibility of
 * the viewer's event participants is computed once, however many server rows the page asks
 * about.
 *
 * The audience is the one of the event's people list (`EventPolicy::viewPeople`: a
 * registered participant of that event, or orga). Somebody who merely has an account
 * learns nothing here, which is what the `share_location` setting promises ("die anderen
 * Teilnehmer sehen ...").
 */
final class PresenceServerOccupants implements ServerOccupants
{
    /** A row shows a handful of faces; the rest is a number. */
    public const int MAX_PEOPLE = 8;

    private bool $snapshotRead = false;

    private ?LocationSnapshot $snapshot = null;

    /** @var array<string, Collection<int, User>> "event id:viewer id" => the participants this viewer may see */
    private array $visible = [];

    /** @var array<string, bool> "event id:viewer id" => whether the viewer may see that event's people */
    private array $allowed = [];

    public function __construct(private readonly PresenceVisibility $visibility) {}

    /**
     * {@inheritDoc}
     *
     * Null for a viewer who may not see that event's people. Otherwise there is always an
     * answer: `{people: [], more: 0}` when the snapshot has nobody located yet.
     */
    public function forServer(int $linkId, Event $event, User $viewer): ?array
    {
        if (! $this->mayViewPeople($event, $viewer)) {
            return null;
        }

        $snapshot = $this->snapshot();

        // A cold cache means "nobody located yet", not "the feature is off": the row stays
        // silent until the refresh lands (PresenceVisibility queues it).
        if ($snapshot === null) {
            return ['people' => [], 'more' => 0];
        }

        /** @var list<array{id: int, name: string, avatarUrl: string|null}> $people */
        $people = [];

        foreach ($this->visibleUsers($event, $viewer) as $user) {
            $game = ($snapshot->people[$user->id] ?? null)?->game;

            // The snapshot knows the link a person's game server is, not only its address:
            // two servers of the same game stay apart, and a Steam-only location never matches.
            if ($game?->linkId !== $linkId) {
                continue;
            }

            $people[] = [
                'id' => $user->id,
                'name' => $user->name,
                // The picture the participant UI renders (the local avatar route), the same
                // one the presence board uses — never the Discord CDN copy.
                'avatarUrl' => $user->avatar_src,
            ];
        }

        usort($people, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']]
            <=> [mb_strtolower($b['name']), $b['id']]);

        return [
            'people' => array_slice($people, 0, self::MAX_PEOPLE),
            'more' => max(0, count($people) - self::MAX_PEOPLE),
        ];
    }

    /** Whether this viewer may see who is at that event at all, asked once per event and viewer. */
    private function mayViewPeople(Event $event, User $viewer): bool
    {
        return $this->allowed[$event->id.':'.$viewer->id] ??= Gate::forUser($viewer)->allows('viewPeople', $event);
    }

    /** The cached snapshot, read once per instance. */
    private function snapshot(): ?LocationSnapshot
    {
        if (! $this->snapshotRead) {
            $this->snapshot = $this->visibility->snapshot();
            $this->snapshotRead = true;
        }

        return $this->snapshot;
    }

    /**
     * Who of the event this viewer may see, computed once per event and viewer: the privacy
     * rule (share_location, blocks, the viewer themselves) is Presence's, not a second one here.
     *
     * @return Collection<int, User>
     */
    private function visibleUsers(Event $event, User $viewer): Collection
    {
        return $this->visible[$event->id.':'.$viewer->id] ??= $this->visibility->visibleParticipants($event, $viewer);
    }
}
