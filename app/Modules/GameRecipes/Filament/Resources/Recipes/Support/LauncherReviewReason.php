<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

use App\Modules\GameRecipes\Support\LauncherSupport;

/**
 * Which rule of {@see LauncherSupport}
 * decides whether the launcher offers a recipe, in the same precedence: the
 * orga's override first, then the pilot, the hand-verified recipes, a browser
 * recipe's `play_url`, the portable archive shape, and otherwise "not
 * offered". A reviewer has to be able to see *why*, not just yes/no.
 */
enum LauncherReviewReason: string
{
    case OverrideOn = 'override_on';
    case OverrideOff = 'override_off';
    case Pilot = 'pilot';
    case Verified = 'verified';
    case Browser = 'browser';
    case Portable = 'portable';
    case None = 'none';

    public function label(): string
    {
        return __('game_recipes.launcher.reason_'.$this->value);
    }
}
