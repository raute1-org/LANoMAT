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
}
