<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;

/**
 * Checks that a finished install really left the game behind. Pelican reports a
 * SteamCMD run that ended with "Missing configuration" (a known cold-start
 * flake: tiny disk usage, no game directory) as a successful install, so the
 * `status` alone proves nothing: the preset names a marker path (for Garry's Mod
 * `garrysmod`) and this looks for it in the server's file list (Client API
 * `files/list`).
 */
final class InstallVerifier
{
    /**
     * True when the marker exists, false when it is missing, null when the file
     * list could not be read (server still locked by the install, panel hiccup),
     * i.e. no verdict yet.
     */
    public static function markerPresent(PelicanClient $client, string $identifier, string $marker): ?bool
    {
        $path = trim($marker, '/');

        if ($path === '') {
            return true;
        }

        $directory = dirname($path) === '.' ? '/' : '/'.dirname($path);
        $name = basename($path);

        try {
            return in_array($name, $client->listFiles($identifier, $directory), true);
        } catch (GameServerException $e) {
            // A missing parent directory is a clear "not there"; anything else is no verdict.
            return $e->httpStatus === 404 ? false : null;
        }
    }
}
