<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

/**
 * What the review queue says about a shared game's file in one glance. A
 * contribution is only fully shareable once its archive is approved, so the
 * list has to tell "waiting", "rejected" and "nothing pinned" apart.
 */
enum ContributionFileState: string
{
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Missing = 'missing';

    public function label(): string
    {
        return __('game_recipes.contribution_file.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Rejected => 'danger',
            self::Missing => 'gray',
        };
    }
}
