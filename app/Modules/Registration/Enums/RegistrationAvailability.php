<?php

namespace App\Modules\Registration\Enums;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventRegistration;

/**
 * THE single place that decides whether people can register for an event.
 *
 * Registration is open while the event is in `registration` OR `live` (walk-ins
 * on site) and capacity remains. Everything that asks "is registration open?"
 * (RegisterForEvent, RegistrationPolicy, controllers, Inertia props, sidebar)
 * goes through here — never compare EventStatus directly.
 */
enum RegistrationAvailability: string
{
    /** Announced: registration will open later. */
    case Upcoming = 'upcoming';
    /** Registration window is open and spots remain. */
    case Open = 'open';
    /** Registration window is open but the participant limit is reached. */
    case Full = 'full';
    /** Draft, finished or archived. */
    case Closed = 'closed';

    /** Event statuses during which registrations are accepted. */
    public static function acceptsStatus(EventStatus $status): bool
    {
        return in_array($status, [EventStatus::Registration, EventStatus::Live], true);
    }

    public static function for(Event $event): self
    {
        if ($event->status === EventStatus::Announced) {
            return self::Upcoming;
        }

        if (! self::acceptsStatus($event->status)) {
            return self::Closed;
        }

        $free = self::freeSpots($event);

        return $free !== null && $free <= 0 ? self::Full : self::Open;
    }

    /**
     * Wire shape shared by every Inertia surface that renders a registration CTA.
     *
     * @return array{availability: string, freeSpots: int|null}
     */
    public static function summary(Event $event): array
    {
        return [
            'availability' => self::for($event)->value,
            'freeSpots' => self::freeSpots($event),
        ];
    }

    /** Remaining spots; null means unlimited. Cancelled registrations do not count. */
    public static function freeSpots(Event $event): ?int
    {
        if ($event->max_participants === null) {
            return null;
        }

        $taken = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->count();

        return max(0, $event->max_participants - $taken);
    }
}
