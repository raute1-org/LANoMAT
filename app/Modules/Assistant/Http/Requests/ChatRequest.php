<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    /** Authorization is the route's role middleware plus the conversation policy in the controller. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'message' => ['required', 'string', 'max:4000'],
        ];
    }
}
