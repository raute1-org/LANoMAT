<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

use App\Modules\GameRecipes\Enums\PullRequestState;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Support\Str;

/**
 * The stored reason of a refused proposal as the list can show it: the first
 * line, shortened. `pr_error` holds every finding of one attempt, one per
 * line, and it is a model column, so a row costs no query.
 */
final class PullRequestReason
{
    /** The start of the first finding; the full text is on the edit page. */
    private const int LIMIT = 80;

    public static function for(GameRecipe $recipe): ?string
    {
        if (! in_array($recipe->pr_state, [PullRequestState::Blocked, PullRequestState::Failed], true)) {
            return null;
        }

        $error = trim((string) $recipe->pr_error);

        if ($error === '') {
            return null;
        }

        $firstLine = trim(explode("\n", $error)[0]);

        return Str::limit($firstLine, self::LIMIT);
    }
}
