<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RefreshLinkedAccountToken;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Jobs\RefreshExpiringTokensJob;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Locks in RefreshExpiringTokensJob's selection logic — derived from
 * LinkedAccountProvider::hasTokenLifecycle() and its own `whereIn` — for the
 * three cases the brief calls out: a Twitch row expiring within the next
 * hour is refreshed, a Steam row (no token lifecycle, null
 * token_expires_at) is skipped, and a Twitch row expiring beyond the next
 * hour is skipped.
 */
it('refreshes only lifecycle-provider accounts expiring within the next hour', function () {
    $expiringTwitch = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Twitch,
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->addMinutes(30),
    ]);
    $farFutureTwitch = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Twitch,
        'access_token' => 'untouched-access',
        'refresh_token' => 'untouched-refresh',
        'token_expires_at' => now()->addHours(6),
    ]);
    $steamAccount = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Steam,
        'access_token' => null,
        'refresh_token' => null,
        'token_expires_at' => null,
    ]);

    fakeLinkedAccounts()->willRefresh(LinkedAccountProvider::Twitch, new LinkedAccountData(
        provider_user_id: $expiringTwitch->provider_user_id,
        access_token: 'refreshed-access',
        refresh_token: 'refreshed-refresh',
        token_expires_at: now()->addHours(4),
    ));

    app(RefreshExpiringTokensJob::class)->handle(app(RefreshLinkedAccountToken::class));

    expect($expiringTwitch->fresh()->access_token)->toBe('refreshed-access')
        ->and($farFutureTwitch->fresh()->access_token)->toBe('untouched-access')
        ->and($steamAccount->fresh()->access_token)->toBeNull()
        ->and($steamAccount->fresh()->token_expires_at)->toBeNull();
});
