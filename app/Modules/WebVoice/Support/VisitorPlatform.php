<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

/** What a user-agent string says about the visitor's machine: which desktop OS, and whether it is a phone or tablet. */
final class VisitorPlatform
{
    /** @return 'linux'|'windows'|'macos'|null */
    public static function os(string $userAgent): ?string
    {
        return match (true) {
            stripos($userAgent, 'Windows') !== false => 'windows',
            stripos($userAgent, 'Macintosh') !== false || stripos($userAgent, 'Mac OS X') !== false => 'macos',
            // Android and ChromeOS also say Linux, but have no build either way: treat them as unknown.
            stripos($userAgent, 'Android') !== false || stripos($userAgent, 'CrOS') !== false => null,
            stripos($userAgent, 'Linux') !== false || stripos($userAgent, 'X11') !== false => 'linux',
            default => null,
        };
    }

    /** Phones and tablets: the client is desktop only, so nothing is advertised to them. */
    public static function isMobile(string $userAgent): bool
    {
        return preg_match('/Android|iPhone|iPad|iPod|Mobile|CrOS|Windows Phone|Silk|Kindle/i', $userAgent) === 1;
    }
}
