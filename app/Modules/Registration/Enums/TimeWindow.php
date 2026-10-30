<?php

namespace App\Modules\Registration\Enums;

/**
 * THE single definition of the arrival/departure time windows.
 *
 * Ordered within a day. `startHour()`/`endHour()` give the representative range
 * the arrival forecast uses (morning begins at midnight, night runs to the end
 * of the day; 24 means "midnight at the end of the day").
 */
enum TimeWindow: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    case Night = 'night';

    /** Position within a day (0..3), also the ordering key. */
    public function order(): int
    {
        return match ($this) {
            self::Morning => 0,
            self::Afternoon => 1,
            self::Evening => 2,
            self::Night => 3,
        };
    }

    public function startHour(): int
    {
        return match ($this) {
            self::Morning => 0,
            self::Afternoon => 12,
            self::Evening => 18,
            self::Night => 22,
        };
    }

    public function endHour(): int
    {
        return match ($this) {
            self::Morning => 12,
            self::Afternoon => 18,
            self::Evening => 22,
            self::Night => 24,
        };
    }

    public function label(): string
    {
        return __('registration.arrival.windows.'.$this->value);
    }

    /** Shorter label for chart axes, e.g. "12–18". */
    public function shortLabel(): string
    {
        return __('registration.arrival.windows_short.'.$this->value);
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        return self::cases();
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $w): array => ['value' => $w->value, 'label' => $w->label()], self::ordered());
    }
}
