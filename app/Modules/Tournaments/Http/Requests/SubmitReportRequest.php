<?php

namespace App\Modules\Tournaments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReportRequest extends FormRequest
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
            'score1' => ['required', 'integer', 'min:0'],
            'score2' => ['required', 'integer', 'min:0'],
        ];
    }
}
