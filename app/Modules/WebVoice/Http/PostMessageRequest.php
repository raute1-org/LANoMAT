<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Http;

use Illuminate\Foundation\Http\FormRequest;

/** Shape check only; who may post is the policy's job inside PostMessage. */
class PostMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('webvoice.chat.max_length')],
        ];
    }
}
