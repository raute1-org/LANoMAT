<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Connectors\FakeLinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\GameOwnershipHint;
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

    /**
     * Whether `$account` owns the given provider-specific app/game id — an
     * ADVISORY signal only (see {@see GameOwnershipHint}),
     * never authoritative and never allowed to block anything.
     *
     * Returns `true` (owns), `false` (confirmed not owned), or `null` when
     * the question cannot be answered: the provider has no ownership
     * concept (e.g. Twitch), the account's profile is private, or the
     * underlying API call failed. Implementations MUST NOT throw — any
     * failure is mapped to `null` instead, since a caller three layers up
     * (tournament enrollment) must never be able to fail because of this
     * check.
     */
    public function ownsApp(LinkedAccount $account, string $appId): ?bool;
}
