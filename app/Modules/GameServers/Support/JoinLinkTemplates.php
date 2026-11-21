<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;

/**
 * Per-game "join this server" options: a registry of URL templates keyed by game slug.
 *
 * A game with templates offers several ways to join (for example the Steam launcher or a
 * natively installed copy). The options are derived on the fly from a server's validated
 * address and port, so nothing is stored and a new game only needs an entry in
 * {@see self::TEMPLATES}. Address and port come from the ServerLink's `join_info`, never from
 * user input at render time, and are validated again here before they reach a URL: a value that
 * is not a plain IPv4/IPv6 address or host name, or a port outside 1..65535, yields no options.
 * Every produced URL is also checked against {@see self::ALLOWED_SCHEMES}.
 *
 * UT2004 (App ID 13230): `steam://run/<appid>//<args>` makes Steam start the game and pass the
 * arguments to UT2004.exe, which takes a server address as its first argument; `ut2004://` is the
 * URL handler the original setup registers. Both forms are untested against a real installation.
 *
 * @phpstan-type JoinOption array{key: string, label: string, url: string, hint: string}
 */
final class JoinLinkTemplates
{
    /** @var list<string> */
    public const ALLOWED_SCHEMES = ['steam', 'ut2004', 'http', 'https'];

    /**
     * Template placeholders: `{hostport}` is the URL-encoded `host:port` (IPv6 in brackets).
     *
     * @var array<string, array{slugs: list<string>, options: list<array{key: string, template: string}>}>
     */
    private const TEMPLATES = [
        'ut2004' => [
            'slugs' => ['unreal-tournament-2004', 'ut2004', 'ut2k4', 'unreal-tournament-2k4'],
            'options' => [
                ['key' => 'steam', 'template' => 'steam://run/13230//{hostport}'],
                ['key' => 'native', 'template' => 'ut2004://{hostport}'],
            ],
        ],
    ];

    /**
     * @return list<JoinOption>
     */
    public static function optionsFor(?Game $game, JoinInfo $info): array
    {
        if ($game === null) {
            return [];
        }

        return self::forSlug((string) $game->slug, $info->address, $info->port);
    }

    /**
     * @return list<JoinOption>
     */
    public static function forSlug(string $slug, ?string $address, ?int $port): array
    {
        $hostPort = self::hostPort($address, $port);

        if ($hostPort === null) {
            return [];
        }

        $slug = strtolower(trim($slug));
        $options = [];

        foreach (self::TEMPLATES as $game => $definition) {
            if (! in_array($slug, $definition['slugs'], true)) {
                continue;
            }

            foreach ($definition['options'] as $option) {
                $url = str_replace('{hostport}', $hostPort, $option['template']);

                if (! self::isAllowedUrl($url)) {
                    continue;
                }

                $options[] = [
                    'key' => $option['key'],
                    'label' => (string) trans("gameservers.join_options.{$game}.{$option['key']}.label"),
                    'url' => $url,
                    'hint' => (string) trans("gameservers.join_options.{$game}.{$option['key']}.hint"),
                ];
            }
        }

        return $options;
    }

    /** True when the URL uses one of the allowed schemes (steam://connect is covered by `steam`). */
    public static function isAllowedUrl(string $url): bool
    {
        if (preg_match('/^([a-z][a-z0-9+.-]*):\/\//', $url, $m) !== 1) {
            return false;
        }

        return in_array($m[1], self::ALLOWED_SCHEMES, true) && preg_match('/[\x00-\x20\x7f"<>\\\\]/', $url) !== 1;
    }

    /** The URL-safe `host:port`, or null when either part is not a plain, valid value. */
    private static function hostPort(?string $address, ?int $port): ?string
    {
        if ($address === null || $port === null || $port < 1 || $port > 65535) {
            return null;
        }

        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.rawurlencode($address).']:'.$port;
        }

        $isIpv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isHost = filter_var($address, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $address) === 1;

        if (! $isIpv4 && ! $isHost) {
            return null;
        }

        return rawurlencode($address).':'.$port;
    }
}
