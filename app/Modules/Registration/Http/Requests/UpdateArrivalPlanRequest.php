<?php

namespace App\Modules\Registration\Http\Requests;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Support\ArrivalPlanRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateArrivalPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Event $event */
        $event = $this->route('event');

        return ArrivalPlanRules::rules($event);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ArrivalPlanRules::messages();
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => ArrivalPlanRules::ensureOrder($validator)];
    }
}
