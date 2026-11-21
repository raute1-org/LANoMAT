<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The client sends only `approve` or `reject` per call id, plus the password
 * typed on a card that asks for one. It never sends tool arguments.
 */
class DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decisions' => ['required', 'array', 'min:1', 'max:20'],
            'decisions.*' => ['required', 'string', 'in:approve,reject'],
            'secrets' => ['nullable', 'array', 'max:20'],
            'secrets.*' => ['nullable', 'string', 'max:128'],
        ];
    }
}
