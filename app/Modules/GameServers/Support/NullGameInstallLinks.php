<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Contracts\GameInstallLinks;

/** Bound while the GameRecipes module is off: a server has no install link. */
final class NullGameInstallLinks implements GameInstallLinks
{
    public function forServer(?string $gameSlug, ?string $gameName, ?string $address, ?int $port, ?string $joinUrl): ?array
    {
        return null;
    }
}
