<?php

namespace App\Modules\Registration\Support;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Carbon\CarbonInterface;

/**
 * THE one place that decides when participants may check themselves in via the
 * on-site QR code: while the event is Live, or while it is in Registration and
 * the start is at most HOURS_BEFORE_START hours away. Documented in
 * docs/self-checkin.md.
 */
class SelfCheckInWindow
{
    public const int HOURS_BEFORE_START = 12;

    public const string OPEN = 'open';

    public const string NOT_OPEN = 'not_open';

    public const string OVER = 'over';

    public static function opensAt(Event $event): ?CarbonInterface
    {
        return $event->starts_at?->subHours(self::HOURS_BEFORE_START);
    }

    /** @return self::OPEN|self::NOT_OPEN|self::OVER */
    public static function state(Event $event, ?CarbonInterface $now = null): string
    {
        $now ??= now();

        return match ($event->status) {
            EventStatus::Live => self::OPEN,
            EventStatus::Registration => ($opens = self::opensAt($event)) !== null && $now->greaterThanOrEqualTo($opens)
                ? self::OPEN
                : self::NOT_OPEN,
            EventStatus::Finished, EventStatus::Archived => self::OVER,
            default => self::NOT_OPEN,
        };
    }

    public static function isOpen(Event $event, ?CarbonInterface $now = null): bool
    {
        return self::state($event, $now) === self::OPEN;
    }
}
