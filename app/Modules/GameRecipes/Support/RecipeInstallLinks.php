<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameServers\Contracts\GameInstallLinks;

/** The recipe catalogue's answer to "how do I get the game this server runs?". */
final class RecipeInstallLinks implements GameInstallLinks
{
    public function forServer(?string $gameSlug, ?string $gameName, ?string $address, ?int $port, ?string $joinUrl): ?array
    {
        return null;
    }
}
