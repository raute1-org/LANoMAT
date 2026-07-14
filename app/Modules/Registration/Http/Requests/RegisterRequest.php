<?php

namespace App\Modules\Registration\Http\Requests;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\RegisterForEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Event $event */
        $event = $this->route('event');

        return [
            'ticket_type' => ['required', 'string', 'max:64', Rule::in(RegisterForEvent::allowedTickets($event))],
        ];
    }
}
