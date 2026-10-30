<?php

namespace App\Modules\Registration\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\TimeWindow;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\ValueObjects\ArrivalPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/** Read model: the arrival plan as Inertia wire data (labels resolved server-side). */
class ArrivalPlanView
{
    /**
     * @return array{days: list<array{value: string, label: string}>, windows: list<array{value: string, label: string}>}
     */
    public static function options(Event $event): array
    {
        return ['days' => EventDays::options($event), 'windows' => TimeWindow::options()];
    }

    /** "Fr, 20.11., 12–18 Uhr" or null when nothing is stated. */
    public static function label(?string $day, ?TimeWindow $window): ?string
    {
        if ($day === null || $window === null) {
            return null;
        }

        return EventDays::label(CarbonImmutable::parse($day)).', '.$window->label();
    }

    /**
     * @return array{arrivalDay: ?string, arrivalWindow: ?string, departureDay: ?string, departureWindow: ?string, arrivalLabel: ?string, departureLabel: ?string, editable: bool}
     */
    public static function summary(EventRegistration $registration, User $user): array
    {
        $plan = ArrivalPlan::fromRegistration($registration);

        return [
            'arrivalDay' => $plan->arrivalDay,
            'arrivalWindow' => $plan->arrivalWindow?->value,
            'departureDay' => $plan->departureDay,
            'departureWindow' => $plan->departureWindow?->value,
            'arrivalLabel' => self::label($plan->arrivalDay, $plan->arrivalWindow),
            'departureLabel' => self::label($plan->departureDay, $plan->departureWindow),
            'editable' => Gate::forUser($user)->allows('updateArrivalPlan', $registration),
        ];
    }
}
