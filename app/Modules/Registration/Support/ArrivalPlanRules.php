<?php

namespace App\Modules\Registration\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\TimeWindow;
use App\Modules\Registration\ValueObjects\ArrivalPlan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** Request-level validation of the optional arrival plan, shared by every form that carries it. */
class ArrivalPlanRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(Event $event): array
    {
        $days = EventDays::dates($event);

        return [
            'arrival_day' => ['nullable', 'date_format:Y-m-d', Rule::in($days), 'required_with:arrival_window'],
            'arrival_window' => ['nullable', Rule::enum(TimeWindow::class), 'required_with:arrival_day'],
            'departure_day' => ['nullable', 'date_format:Y-m-d', Rule::in($days), 'required_with:departure_window'],
            'departure_window' => ['nullable', Rule::enum(TimeWindow::class), 'required_with:departure_day'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'arrival_day.in' => __('registration.arrival.validation.day'),
            'departure_day.in' => __('registration.arrival.validation.day'),
            'arrival_day.required_with' => __('registration.arrival.validation.day_required'),
            'departure_day.required_with' => __('registration.arrival.validation.day_required'),
            'arrival_window.required_with' => __('registration.arrival.validation.window_required'),
            'departure_window.required_with' => __('registration.arrival.validation.window_required'),
        ];
    }

    /** Arrival must not be after departure (only checked once the fields are individually valid). */
    public static function ensureOrder(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $plan = ArrivalPlan::fromValidated($validator->validated());

        if ($plan->hasArrival() && $plan->hasDeparture() && $plan->arrivalKey() > $plan->departureKey()) {
            $validator->errors()->add('departure_day', __('registration.arrival.errors.order'));
        }
    }
}
