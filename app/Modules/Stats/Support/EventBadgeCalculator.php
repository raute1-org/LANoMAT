<?php

declare(strict_types=1);

namespace App\Modules\Stats\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Voting\Support\MvpPollQuery;

/**
 * Computed, event-scoped badges — currently just `mvp_of_the_night` for the
 * winner of the event's closed "player of the evening" poll. Never stored,
 * always derived on read (same philosophy as the cross-event
 * {@see BadgeCalculator}).
 *
 * Deliberately a *separate* class from {@see BadgeCalculator}: that one
 * aggregates cross-event tournament history for a single competitor and has
 * no event scope at all, while the MVP badge is inherently per-event (one
 * closed MVP poll per event). Bolting an event argument onto
 * `BadgeCalculator::for()` would conflate the two different aggregation
 * scopes, so this stays a distinct, event-keyed lookup instead.
 */
class EventBadgeCalculator
{
    /**
     * @return array<int, list<string>> userId => badge slugs
     */
    public static function forEvent(Event $event): array
    {
        $poll = MvpPollQuery::closedFor($event);

        if ($poll === null) {
            return [];
        }

        $winner = MvpPollQuery::winner($poll);

        if ($winner === null || $winner->subject_user_id === null) {
            return [];
        }

        return [
            $winner->subject_user_id => ['mvp_of_the_night'],
        ];
    }
}
