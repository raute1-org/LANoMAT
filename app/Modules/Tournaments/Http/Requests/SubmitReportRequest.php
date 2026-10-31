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
            'score1' => ['bail', 'required', 'integer', 'min:0', 'max:9999'],
            // A match cannot end in a tie — score2 must differ from score1
            // (see BracketProgressor::apply()'s "no draws" domain rule).
            // `bail` + `different` (not `Rule::notIn([input])`): an array-valued
            // score must fail validation (422), not blow up while the rule
            // string is built (F-R2A-8).
            'score2' => ['bail', 'required', 'integer', 'min:0', 'max:9999', 'different:score1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'score2.different' => trans('tournaments.errors.tied_score'),
        ];
    }
}
