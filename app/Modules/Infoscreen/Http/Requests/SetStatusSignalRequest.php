<?php

namespace App\Modules\Infoscreen\Http\Requests;

use App\Modules\Infoscreen\Actions\SetStatusSignal;
use App\Modules\Infoscreen\Enums\StatusLevel;
use App\Modules\Infoscreen\Models\StatusSignal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the helper control page's "set status" form. This is a UX-layer
 * guard only — {@see SetStatusSignal}
 * re-validates `component` itself, since the action has more than one
 * possible entry point and must never trust a caller to have gone through
 * this request.
 */
class SetStatusSignalRequest extends FormRequest
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
        return [
            'component' => ['required', 'string', Rule::in(StatusSignal::COMPONENTS)],
            'level' => ['required', Rule::enum(StatusLevel::class)],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
