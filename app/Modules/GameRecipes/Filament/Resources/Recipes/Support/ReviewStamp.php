<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

use App\Modules\GameRecipes\Enums\RecipeStatus;

/**
 * Review metadata is transition-based: a recipe is stamped when it *enters*
 * Published. Editing the content of a published recipe (or flipping it back to
 * draft) keeps the original stamp — it records who released this recipe to the
 * participants, not who touched it last. Republishing an archived recipe is a
 * new release and gets the current reviewer.
 */
final class ReviewStamp
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forTransition(array $data, ?RecipeStatus $previous): array
    {
        if ($previous === RecipeStatus::Published || self::status($data['status'] ?? null) !== RecipeStatus::Published) {
            return $data;
        }

        $data['reviewed_by'] = auth()->id();
        $data['reviewed_at'] = now();

        return $data;
    }

    private static function status(mixed $status): ?RecipeStatus
    {
        if ($status instanceof RecipeStatus) {
            return $status;
        }

        return is_string($status) ? RecipeStatus::tryFrom($status) : null;
    }
}
