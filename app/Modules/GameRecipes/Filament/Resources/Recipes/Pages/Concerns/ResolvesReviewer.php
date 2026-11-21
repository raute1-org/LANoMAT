<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

/**
 * The orga who releases the recipe. Filament's `authMiddleware` guarantees a
 * logged-in user reaches these pages, so a null here means the framework's own
 * auth guarantee was broken — surfacing that instead of widening every action
 * to `?User` (mirrors the Files review queue).
 */
trait ResolvesReviewer
{
    protected function reviewer(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
