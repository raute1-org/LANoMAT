<?php

namespace App\Modules\Teams\Rules;

use App\Modules\Teams\Models\Team;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Team names are unique case-insensitively (mirrors the `lower(name)` unique
 * index), so the tournament team picker never lists two identical entries.
 */
class UniqueTeamName implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreTeamId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $taken = Team::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreTeamId !== null, fn ($query) => $query->whereKeyNot($this->ignoreTeamId))
            ->exists();

        if ($taken) {
            $fail(__('teams.errors.name_taken'));
        }
    }
}
