<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Enums;

enum RecipeStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return __('game_recipes.status.'.$this->value);
    }

    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
