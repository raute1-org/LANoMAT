<?php

namespace App\Modules\Teams\Http\Requests;

use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Rules\UniqueTeamName;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:64', new UniqueTeamName($this->routeTeamId())],
            'tag' => ['required', 'string', 'max:16'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ];
    }

    private function routeTeamId(): ?int
    {
        $team = $this->route('team');

        return $team instanceof Team ? $team->id : null;
    }
}
