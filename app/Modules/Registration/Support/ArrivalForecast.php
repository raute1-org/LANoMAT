<?php

namespace App\Modules\Registration\Support;

use App\Modules\Registration\Enums\TimeWindow;
use App\Modules\Registration\ValueObjects\ArrivalPlan;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Pure, IO-free arrival forecast: turns the participants' stated plans into a
 * day x window slot matrix. See docs/arrival-planning.md for the counting rules.
 *
 * Rules (the only place they live):
 * - A registration is "placed" when it has a complete arrival (day + window)
 *   on one of the event days. Everything else is "unknown" and is reported
 *   separately, never counted as present.
 * - A departure counts only when complete, on an event day and not before the
 *   arrival slot; otherwise the person is assumed to stay until the end.
 * - present(slot) = placed arrivals in slots <= slot  minus  departures in
 *   slots < slot. A person leaving in the same window they arrive in is
 *   counted as present for that window (conservative peak).
 */
final readonly class ArrivalForecast
{
    /**
     * @param  list<array{index: int, day: string, window: TimeWindow, startsAt: CarbonImmutable, endsAt: CarbonImmutable, arrivals: int, departures: int, present: int}>  $slots
     * @param  array{index: int, count: int}|null  $peak
     */
    private function __construct(
        public array $slots,
        public int $registered,
        public int $placed,
        public int $unknown,
        public ?array $peak,
        public int $expectedArrivedByNow,
    ) {}

    /**
     * @param  list<CarbonImmutable>  $days  event days (start-of-day, app timezone)
     * @param  iterable<ArrivalPlan>  $plans  one per non-cancelled registration
     */
    public static function compute(array $days, iterable $plans, DateTimeInterface $now): self
    {
        $meta = [];
        $lookup = [];

        foreach ($days as $day) {
            foreach (TimeWindow::ordered() as $window) {
                $lookup[$day->toDateString().'#'.$window->value] = count($meta);
                $meta[] = [
                    'day' => $day->toDateString(),
                    'window' => $window,
                    'startsAt' => $day->setTime($window->startHour(), 0),
                    'endsAt' => $day->setTime(0, 0)->addHours($window->endHour()),
                ];
            }
        }

        $arrivals = array_fill(0, count($meta), 0);
        $departures = array_fill(0, count($meta), 0);
        $registered = 0;
        $placed = 0;

        foreach ($plans as $plan) {
            $registered++;

            $arrival = $plan->hasArrival() ? ($lookup[$plan->arrivalDay.'#'.$plan->arrivalWindow?->value] ?? null) : null;
            if ($arrival === null) {
                continue;
            }

            $placed++;
            $arrivals[$arrival]++;

            $departure = $plan->hasDeparture() ? ($lookup[$plan->departureDay.'#'.$plan->departureWindow?->value] ?? null) : null;
            if ($departure !== null && $departure >= $arrival) {
                $departures[$departure]++;
            }
        }

        $slots = [];
        $arrived = 0;
        $departed = 0;
        $peak = null;
        $byNow = 0;
        $nowInstant = CarbonImmutable::instance($now);

        foreach ($meta as $i => $slotMeta) {
            $arrived += $arrivals[$i];
            $present = $arrived - $departed;
            $departed += $departures[$i];

            $slots[] = [...$slotMeta, 'index' => $i, 'arrivals' => $arrivals[$i], 'departures' => $departures[$i], 'present' => $present];

            if ($present > 0 && ($peak === null || $present > $peak['count'])) {
                $peak = ['index' => $i, 'count' => $present];
            }
            if ($slotMeta['startsAt']->lessThanOrEqualTo($nowInstant)) {
                $byNow += $arrivals[$i];
            }
        }

        return new self($slots, $registered, $placed, $registered - $placed, $peak, $byNow);
    }

    /**
     * "Sa, 21.11. 12–18 Uhr" for slot display.
     *
     * @param  array{startsAt: CarbonImmutable, window: TimeWindow}  $slot
     */
    public static function slotLabel(array $slot): string
    {
        return EventDays::label($slot['startsAt']->startOfDay()).' '.$slot['window']->label();
    }
}
