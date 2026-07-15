<?php

namespace App\Modules\Lfg\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateLfgPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Deliberately no `user_id`/`event_id` rule: both are always resolved
     * server-side by CreateLfgPost from the authenticated user and the
     * route-bound event, never accepted from client input (see
     * LfgController::store).
     *
     * `duration_hours` gets an explicit upper bound of 168 (7 days) — the
     * CreateLfgPost action itself only rejects non-positive values, so this
     * is the one place a caller-facing sanity cap belongs.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'game' => ['nullable', 'string', 'max:64'],
            'body' => ['nullable', 'string', 'max:1000'],
            'slots_needed' => ['nullable', 'integer', 'min:1', 'max:64'],
            'duration_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ];
    }
}
