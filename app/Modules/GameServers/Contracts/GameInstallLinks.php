<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Contracts;

/**
 * How a player gets the game a server runs. GameServers owns the question and
 * never imports the recipe catalogue; the GameRecipes module answers it while
 * it is enabled, and a null implementation answers "nothing" otherwise.
 */
interface GameInstallLinks
{
    /**
     * The published recipe that installs the game of one server, or null when
     * there is none the players may see.
     *
     * `launcherJoinUrl` is the `lanomat://join/...` link that installs the game
     * when needed and starts it on this server; it is null when the launcher
     * cannot install the recipe or the server has nothing to join.
     *
     * @param  string|null  $joinUrl  the server's own join URL (steam://, ut2004://, https://), when it has one
     * @return array{recipeSlug: string, title: string, pageUrl: string, launcherSupported: bool, launcherJoinUrl: string|null}|null
     */
    public function forServer(?string $gameSlug, ?string $gameName, ?string $address, ?int $port, ?string $joinUrl): ?array;
}
