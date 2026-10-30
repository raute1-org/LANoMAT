<?php

namespace App\Modules\Registration\ValueObjects;

use App\Modules\Registration\Enums\TimeWindow;
use App\Modules\Registration\Models\EventRegistration;

/**
 * A participant's stated arrival and departure (day + window each). Every part
 * is optional: null means "weiß ich noch nicht". Pure value object, no IO.
 */
final readonly class ArrivalPlan
{
    public function __construct(
        public ?string $arrivalDay = null,
        public ?TimeWindow $arrivalWindow = null,
        public ?string $departureDay = null,
        public ?TimeWindow $departureWindow = null,
    ) {}

    /** @param array<string, mixed> $data validated request data */
    public static function fromValidated(array $data): self
    {
        $window = fn (mixed $v): ?TimeWindow => is_string($v) && $v !== '' ? TimeWindow::from($v) : null;
        $day = fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return new self(
            $day($data['arrival_day'] ?? null),
            $window($data['arrival_window'] ?? null),
            $day($data['departure_day'] ?? null),
            $window($data['departure_window'] ?? null),
        );
    }

    public static function fromRegistration(EventRegistration $registration): self
    {
        return new self(
            $registration->arrival_day?->toDateString(),
            $registration->arrival_window,
            $registration->departure_day?->toDateString(),
            $registration->departure_window,
        );
    }

    public function hasArrival(): bool
    {
        return $this->arrivalDay !== null && $this->arrivalWindow !== null;
    }

    public function hasDeparture(): bool
    {
        return $this->departureDay !== null && $this->departureWindow !== null;
    }

    /** Sortable position of the arrival, "Y-m-d" + window order. */
    public function arrivalKey(): ?string
    {
        return $this->hasArrival() ? $this->arrivalDay.'#'.$this->arrivalWindow?->order() : null;
    }

    public function departureKey(): ?string
    {
        return $this->hasDeparture() ? $this->departureDay.'#'.$this->departureWindow?->order() : null;
    }

    /**
     * Structural validity against the event's days: halves are complete, days
     * are event days, arrival is not after departure.
     *
     * @param  list<string>  $eventDates  Y-m-d
     */
    public function isValidFor(array $eventDates): bool
    {
        if (($this->arrivalDay === null) !== ($this->arrivalWindow === null)) {
            return false;
        }
        if (($this->departureDay === null) !== ($this->departureWindow === null)) {
            return false;
        }
        if ($this->arrivalDay !== null && ! in_array($this->arrivalDay, $eventDates, true)) {
            return false;
        }
        if ($this->departureDay !== null && ! in_array($this->departureDay, $eventDates, true)) {
            return false;
        }

        return ! ($this->hasArrival() && $this->hasDeparture()
            && $this->arrivalKey() > $this->departureKey());
    }

    public function isEmpty(): bool
    {
        return $this->arrivalDay === null && $this->departureDay === null;
    }
}
