<?php

namespace App\Modules\Teams\Http\Requests;

use App\Modules\Teams\Rules\UniqueTeamName;
use Illuminate\Foundation\Http\FormRequest;

class StoreTeamRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:64', new UniqueTeamName],
            'tag' => ['required', 'string', 'max:16'],
        ];
    }
}
