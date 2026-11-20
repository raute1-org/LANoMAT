<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use App\Modules\Identity\Enums\LinkedAccountProvider;
use DomainException;

class IdentityException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function unknownLinkedAccountProvider(LinkedAccountProvider $provider): self
    {
        return new self(
            "No LinkedAccountConnector is bound for provider [{$provider->value}].",
            'identity.errors.unknown_linked_account_provider',
        );
    }

    public static function unsupportedTokenRefresh(LinkedAccountProvider $provider): self
    {
        return new self(
            "The [{$provider->value}] provider does not support token refresh.",
            'identity.errors.unsupported_token_refresh',
        );
    }

    public static function tokenRefreshFailed(LinkedAccountProvider $provider): self
    {
        return new self(
            "Refreshing the [{$provider->value}] account's access token failed.",
            'identity.errors.token_refresh_failed',
        );
    }

    public static function accountAlreadyLinked(LinkedAccountProvider $provider): self
    {
        return new self(
            "This [{$provider->value}] account is already linked to a different user.",
            'identity.errors.account_already_linked',
        );
    }

    public static function cannotChangeOwnRole(): self
    {
        return new self('An admin cannot change their own role.', 'identity.errors.cannot_change_own_role');
    }

    public static function lastAdmin(): self
    {
        return new self('The last remaining admin cannot be demoted.', 'identity.errors.last_admin');
    }
}
