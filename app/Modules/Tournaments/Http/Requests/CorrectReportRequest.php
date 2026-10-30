<?php

namespace App\Modules\Tournaments\Http\Requests;

/** The corrected "own : opponent" scores plus the match's optimistic `lock_version`. */
class CorrectReportRequest extends SubmitReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'lock_version' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
