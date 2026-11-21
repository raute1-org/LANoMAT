<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameServers\Support\JoinLinkTemplates;

/**
 * The `lanomat://join/<slug>` deep link: one click that installs a game when
 * it is missing and starts it on the given server, exactly the rules the
 * `/launcher` start page has always applied.
 *
 * The link carries the server one of two ways. UT2004 takes the server
 * address as its first argument, so the launcher can start the freshly
 * installed game already on the server (`?address=<host>:<port>`); every other
 * recipe launches normally and then opens the server's own join URL
 * (`?url=<urlencoded url>`, for example a `steam://connect/...` link). A server
 * with neither, or a join URL the browser would refuse to open, yields no
 * link — the caller then keeps the server's own fallback action.
 */
final class LauncherJoinLink
{
    /** The one recipe whose launcher path passes the server address on the command line. */
    public const ADDRESS_RECIPE = 'unreal-tournament-2004';

    /**
     * The deep link, or null when the server has neither a usable address+port
     * nor an accepted join URL (there would be nothing to join).
     */
    public function build(string $slug, ?string $address, ?int $port, ?string $joinUrl): ?string
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        $hostPort = $this->hostPort($address, $port);
        $url = $this->acceptedJoinUrl($joinUrl);

        if ($hostPort === null && $url === null) {
            return null;
        }

        $link = 'lanomat://join/'.$slug;

        if ($slug === self::ADDRESS_RECIPE && $hostPort !== null) {
            return $link.'?address='.rawurlencode($hostPort);
        }

        if ($url !== null) {
            return $link.'?url='.rawurlencode($url);
        }

        return $link;
    }

    /** `host:port` (IPv6 bracketed), the form the launcher's launch uses; null when either part is missing. */
    private function hostPort(?string $address, ?int $port): ?string
    {
        if ($address === null || trim($address) === '' || $port === null || $port < 1 || $port > 65535) {
            return null;
        }

        $address = trim($address);

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$address.']:'.$port
            : $address.':'.$port;
    }

    /** The server's own join URL when the browser may open it, otherwise nothing. */
    private function acceptedJoinUrl(?string $joinUrl): ?string
    {
        if ($joinUrl === null || trim($joinUrl) === '') {
            return null;
        }

        return JoinLinkTemplates::isAllowedUrl($joinUrl) ? $joinUrl : null;
    }
}
