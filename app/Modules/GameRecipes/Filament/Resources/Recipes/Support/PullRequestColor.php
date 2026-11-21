<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

use App\Modules\GameRecipes\Enums\PullRequestState;

/** The Filament badge colour for a recipe pull request state (list and edit page). */
final class PullRequestColor
{
    public static function for(?PullRequestState $state): ?string
    {
        return match ($state) {
            PullRequestState::Open => 'info',
            PullRequestState::Merged => 'success',
            PullRequestState::Closed => 'gray',
            PullRequestState::Blocked => 'warning',
            PullRequestState::Failed => 'danger',
            null => null,
        };
    }
}
