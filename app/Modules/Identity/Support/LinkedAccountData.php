<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Contracts\LinkedAccountConnector;
use App\Modules\Identity\Models\LinkedAccount;
use Carbon\CarbonImmutable;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\User as SocialiteTwoUser;

/**
 * The provider-agnostic result of resolving (or refreshing) a linked
 * account, produced by a {@see LinkedAccountConnector}.
 * Kept separate from the {@see LinkedAccount}
 * Eloquent model so connectors never touch persistence directly — the
 * calling Action decides how to store this.
 */
final readonly class LinkedAccountData
{
    /**
     * @param  array<int, string>  $scopes
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $provider_user_id,
        public ?string $nickname = null,
        public ?string $access_token = null,
        public ?string $refresh_token = null,
        public ?CarbonImmutable $token_expires_at = null,
        public array $scopes = [],
        public array $meta = [],
    ) {}

    /**
     * Maps a Socialite user to the provider-agnostic shape. Every
     * OAuth-specific field is guarded because Socialite's OpenID-based
     * drivers (Steam) return a plain {@see SocialiteUser} with none of
     * the token/refreshToken/expiresIn/approvedScopes properties — those
     * only exist on the concrete OAuth2 {@see SocialiteTwoUser}.
     */
    public static function fromSocialite(SocialiteUser $u): self
    {
        $isOauth2 = $u instanceof SocialiteTwoUser;

        return new self(
            provider_user_id: (string) $u->getId(),
            nickname: $u->getNickname() ?? $u->getName(),
            access_token: $isOauth2 ? $u->token : null,
            refresh_token: $isOauth2 ? $u->refreshToken : null,
            token_expires_at: $isOauth2 && $u->expiresIn !== null
                ? CarbonImmutable::now()->addSeconds($u->expiresIn)
                : null,
            scopes: $isOauth2 ? $u->approvedScopes : [],
        );
    }
}
