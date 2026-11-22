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
     * `lanReady` says whether the game can be installed fresh from the LAN
     * right now: true when every required base archive or disc image of the
     * recipe is an approved upload of a non-draft event (pinned, or matched by
     * file name) or names an external download a player can follow. It is the
     * install plan's own rule asked without a viewer and without an event, so
     * it never depends on who looks. The plan can still differ in the details:
     * it narrows matched uploads to the plan's event, and it skips a base
     * authored for another OS. So the answer is what the launcher's plan finds
     * (it asks without an event), and it is cautious about platforms: one
     * missing base makes it false although another platform's base may be
     * there, which is why the website says a fresh install "can" fail, not
     * that it will. `launcherJoinUrl` and `launcherSupported` do
     * not depend on `lanReady` — whoever already has the game joins with one
     * click, only a fresh install needs the files.
     *
     * A recipe without a required base or disc image is ready, because nothing
     * has to be fetched: a browser recipe (`play_url`) installs nothing, and a
     * hand-verified recipe is judged by its own requirements like every other
     * one (UT2004's disc image carries an external download, NFSU2's base
     * archive has neither and needs the LAN copy). Optional requirements,
     * patches, tools, mods and avatars never make a game "not ready" — they are
     * not the game.
     *
     * @param  string|null  $joinUrl  the server's own join URL (steam://, ut2004://, https://), when it has one
     * @return array{recipeSlug: string, title: string, pageUrl: string, launcherSupported: bool, launcherJoinUrl: string|null, lanReady: bool}|null
     */
    public function forServer(?string $gameSlug, ?string $gameName, ?string $address, ?int $port, ?string $joinUrl): ?array;
}
