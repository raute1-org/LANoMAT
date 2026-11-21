<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

use App\Models\User;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;

/**
 * Which statuses a user may pick in the form. The policy is the source of
 * truth: without the review ability a user can only keep/propose a draft, so
 * the Select never becomes an ungated publish path.
 */
final class RecipeStatusOptions
{
    /** @return array<string, string> */
    public static function for(?User $user): array
    {
        $options = [];

        foreach (RecipeStatus::cases() as $status) {
            if (! self::canReview($user) && $status !== RecipeStatus::Draft) {
                continue;
            }

            $options[$status->value] = $status->label();
        }

        return $options;
    }

    public static function canReview(?User $user): bool
    {
        return $user !== null && $user->can('reviewAny', GameRecipe::class);
    }
}
