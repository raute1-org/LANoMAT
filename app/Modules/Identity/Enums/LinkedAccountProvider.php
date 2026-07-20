<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

enum LinkedAccountProvider: string
{
    case Steam = 'steam';
    case Twitch = 'twitch';
    case BattleNet = 'battlenet';
    case Epic = 'epic';
    case Gog = 'gog';

    public function label(): string
    {
        return match ($this) {
            self::Steam => 'Steam',
            self::Twitch => 'Twitch',
            self::BattleNet => 'Battle.net',
            self::Epic => 'Epic Games',
            self::Gog => 'GOG',
        };
    }

    /**
     * Whether this provider is linked via an OAuth-style authorization flow.
     * Steam uses OpenID (no OAuth token issued), all others OAuth2.
     */
    public function isOauth(): bool
    {
        return $this !== self::Steam;
    }

    /**
     * Whether this provider issues a refresh token that needs periodic
     * renewal. True only for OAuth providers that actually hand out a
     * refresh token — currently just Twitch.
     */
    public function hasTokenLifecycle(): bool
    {
        return $this->isOauth() && $this === self::Twitch;
    }

    public function socialiteDriver(): string
    {
        return match ($this) {
            self::Steam => 'steam',
            self::Twitch => 'twitch',
            self::BattleNet => 'battlenet',
            self::Epic => 'epic',
            self::Gog => 'gog',
        };
    }

    /**
     * Providers currently offered for linking. The remaining enum cases
     * exist for the future but are not yet wired into any linking UI.
     *
     * @return array<int, self>
     */
    public static function linkable(): array
    {
        return [self::Steam, self::Twitch];
    }
}
