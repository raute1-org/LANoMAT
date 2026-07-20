<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Connectors\FakeLinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;

/**
 * A single third-party identity provider (Steam, Twitch, ...) accessed only
 * through this contract — real HTTP/OAuth calls happen in the concrete
 * implementations (9.3/9.4), tests use {@see FakeLinkedAccountConnector}.
 */
interface LinkedAccountConnector
{
    public function provider(): LinkedAccountProvider;

    /**
     * The URL to redirect the user to in order to start linking this
     * provider's account (Socialite's OAuth/OpenID authorization step).
     */
    public function redirectUrl(): string;

    /**
     * Resolves the provider's callback (the current request) into the
     * provider-agnostic linked-account data.
     */
    public function resolveCallback(): LinkedAccountData;

    /**
     * Exchanges the account's refresh token for a fresh access token.
     *
     * @throws IdentityException when the provider has no token lifecycle
     *                           (e.g. Steam, see {@see LinkedAccountProvider::hasTokenLifecycle()}).
     */
    public function refresh(LinkedAccount $account): LinkedAccountData;
}
