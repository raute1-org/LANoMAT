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
     * PostgreSQL rejects NUL bytes in text and jsonb, so they are removed
     * before validation instead of surfacing as a 500 (F-R2B-12).
     */
    protected function prepareForValidation(): void
    {
        $note = $this->input('note');

        if (is_string($note)) {
            $this->merge(['note' => str_replace("\0", '', $note)]);
        }
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
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
