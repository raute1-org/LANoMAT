<?php

namespace App\Modules\Tournaments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // A match cannot end in a tie — score2 must differ from score1
            // (see BracketProgressor::apply()'s "no draws" domain rule).
            'score2' => ['required', 'integer', 'min:0', Rule::notIn([$this->input('score1')])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'score2.not_in' => trans('tournaments.errors.tied_score'),
        ];
    }
}
