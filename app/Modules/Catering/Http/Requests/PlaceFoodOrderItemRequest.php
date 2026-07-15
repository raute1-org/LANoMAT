<?php

namespace App\Modules\Catering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceFoodOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Deliberately no `price_cents` rule: the price is always resolved
     * server-side from the FoodOrder's menu by PlaceFoodOrderItem, never
     * accepted from client input (see CateringController::store).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'option_key' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
