<?php

declare(strict_types=1);

namespace App\Modules\Identity\Connectors;

use App\Modules\Identity\Contracts\LinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;
use App\Modules\Voice\Testing\FakeVoiceClient;
use Carbon\CarbonImmutable;

/**
 * In-memory {@see LinkedAccountConnector} for tests — never calls a real
 * API. Bound per provider by the `fakeLinkedAccounts()` test helper (mirrors
 * {@see FakeVoiceClient}).
 */
class FakeLinkedAccountConnector implements LinkedAccountConnector
{
    private ?LinkedAccountData $nextResolve = null;

    private ?LinkedAccountData $nextRefresh = null;

    private bool $failRefresh = false;

    private bool $reportsOwnership = true;

    /**
     * The queued answer for {@see ownsApp()}: true/false, or null for
     * "unknown" (private profile / API failure / provider can't answer).
     * Defaults to `true` so tests that never call `willReportOwnership()`
     * keep the pre-9.7 "owns everything" behaviour.
     */
    private ?bool $ownsApp = true;

    public function __construct(private readonly LinkedAccountProvider $provider) {}

    public function provider(): LinkedAccountProvider
    {
        return $this->provider;
    }

    public function redirectUrl(): string
    {
        return "https://fake-oauth.test/{$this->provider->value}/authorize";
    }

    public function willResolve(LinkedAccountData $data): void
    {
        $this->nextResolve = $data;
    }

    public function willRefresh(LinkedAccountData $data): void
    {
        $this->nextRefresh = $data;
        $this->failRefresh = false;
    }

    public function willFailRefresh(): void
    {
        $this->failRefresh = true;
        $this->nextRefresh = null;
    }

    /**
     * Queues the answer {@see ownsApp()} returns for every subsequent call
     * on this fake — true (owns) or false (confirmed not owned). Use
     * {@see willReportOwnershipUnknown()} to queue the third state (null).
     */
    public function willReportOwnership(bool $owns): void
    {
        $this->reportsOwnership = $owns;
        $this->ownsApp = $owns;
    }

    /**
     * Queues an "unknown" ownership answer (null) — mirrors a private
     * profile or a failed API call in the real SteamConnector.
     */
    public function willReportOwnershipUnknown(): void
    {
        $this->ownsApp = null;
    }

    public function reportsOwnership(): bool
    {
        return $this->reportsOwnership;
    }

    public function ownsApp(LinkedAccount $account, string $appId): ?bool
    {
        return $this->ownsApp;
    }

    public function resolveCallback(): LinkedAccountData
    {
        return $this->nextResolve ?? new LinkedAccountData(
            provider_user_id: 'fake-'.$this->provider->value.'-id',
            nickname: 'FakeUser',
            access_token: $this->provider->isOauth() ? 'fake-access-token' : null,
            refresh_token: $this->provider->hasTokenLifecycle() ? 'fake-refresh-token' : null,
            token_expires_at: $this->provider->hasTokenLifecycle()
                ? CarbonImmutable::now()->addHour()
                : null,
        );
    }

    public function refresh(LinkedAccount $account): LinkedAccountData
    {
        if (! $this->provider->hasTokenLifecycle()) {
            throw IdentityException::unsupportedTokenRefresh($this->provider);
        }

        if ($this->failRefresh) {
            throw IdentityException::tokenRefreshFailed($this->provider);
        }

        return $this->nextRefresh ?? new LinkedAccountData(
            provider_user_id: $account->provider_user_id,
            nickname: $account->nickname,
            access_token: 'fake-refreshed-access-token',
            refresh_token: 'fake-refreshed-refresh-token',
            token_expires_at: CarbonImmutable::now()->addHour(),
        );
    }
}
