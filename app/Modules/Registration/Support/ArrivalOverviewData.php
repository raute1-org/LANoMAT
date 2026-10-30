<?php

namespace App\Modules\Registration\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationAvailability;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\ValueObjects\ArrivalPlan;
use Carbon\CarbonImmutable;

/**
 * Read model for the orga arrival overview: loads the event's non-cancelled
 * registrations and hands their plans to the pure {@see ArrivalForecast}.
 */
final readonly class ArrivalOverviewData
{
    public function __construct(
        public ArrivalForecast $forecast,
        public int $checkedIn,
        public ?int $freeSpots,
    ) {}

    public static function for(Event $event, ?CarbonImmutable $now = null): self
    {
        $registrations = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', RegistrationStatus::Cancelled->value)
            ->get(['id', 'checked_in_at', 'arrival_day', 'arrival_window', 'departure_day', 'departure_window']);

        $forecast = ArrivalForecast::compute(
            EventDays::for($event),
            $registrations->map(fn (EventRegistration $r): ArrivalPlan => ArrivalPlan::fromRegistration($r))->all(),
            $now ?? CarbonImmutable::now(),
        );

        return new self(
            $forecast,
            $registrations->whereNotNull('checked_in_at')->count(),
            RegistrationAvailability::freeSpots($event),
        );
    }
}
