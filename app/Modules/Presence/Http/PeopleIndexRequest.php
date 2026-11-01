<?php

declare(strict_types=1);

namespace App\Modules\Presence\Http;

use Illuminate\Foundation\Http\FormRequest;

/** Filters and paging of the people list. Access is decided by the policy in the controller. */
class PeopleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'team' => ['sometimes', 'boolean'],
            'friends' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:60'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
