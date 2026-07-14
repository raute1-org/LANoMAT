<?php

namespace App\Modules\Tournaments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmReportRequest extends FormRequest
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
            'lock_version' => ['required', 'integer'],
        ];
    }
}
