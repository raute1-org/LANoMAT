<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Support\FriendService;
use App\Modules\Presence\Jobs\RefreshLocationSnapshotJob;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Collection;

/**
 * The one place that decides who of an event's participants a viewer may see located, and where
 * the cached {@see LocationSnapshot} is read from. {@see PeopleDirectory} and {@see LocatedGames}
 * both go through it, so they cannot drift apart:
 *  - participants are the non-cancelled registrations of the event;
 *  - somebody who switched `share_location` off is not visible to others;
 *  - somebody blocked either way with the viewer is left out like an opt-out;
 *  - the viewer always sees themselves (when they are a participant).
 */
final class PresenceVisibility
{
    public function __construct(
        private readonly LocationSnapshotStore $store,
        private readonly Modules $modules,
        private readonly FriendService $friends,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function participants(Event $event): Collection
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->with('user')
            ->get()
            ->map(fn (EventRegistration $registration): ?User => $registration->user)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * The participants this viewer may see, the viewer themselves included.
     *
     * @return Collection<int, User>
     */
    public function visibleParticipants(Event $event, User $viewer): Collection
    {
        $blocked = $this->blockedIds($viewer);

        return $this->participants($event)
            ->filter(fn (User $user): bool => $user->id === $viewer->id
                || ($user->share_location && ! in_array($user->id, $blocked, true)))
            ->values();
    }

    /**
     * @return list<int>
     */
    public function blockedIds(User $viewer): array
    {
        return $this->modules->enabled(Module::Friends) ? $this->friends->blockedUserIdsEitherWay($viewer) : [];
    }

    /** The cached snapshot; a cold cache has it built (inline on a sync queue, in the background otherwise). */
    public function snapshot(): ?LocationSnapshot
    {
        $snapshot = $this->store->get();

        if ($snapshot === null) {
            RefreshLocationSnapshotJob::dispatch();
            $snapshot = $this->store->get();
        }

        return $snapshot;
    }
}
