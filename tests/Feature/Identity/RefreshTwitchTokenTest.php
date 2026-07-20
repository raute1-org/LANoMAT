<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RefreshLinkedAccountToken;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;
use App\Modules\Notifications\Notifications\LinkedAccountReauthRequired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('refreshes an expiring Twitch token', function () {
    $account = LinkedAccount::factory()->create([
        'provider' => LinkedAccountProvider::Twitch,
        'token_expires_at' => now()->addMinutes(10),
    ]);
    fakeLinkedAccounts()->willRefresh(LinkedAccountProvider::Twitch, new LinkedAccountData(
        provider_user_id: $account->provider_user_id, access_token: 'new', refresh_token: 'newr',
        token_expires_at: now()->addHours(4),
    ));

    app(RefreshLinkedAccountToken::class)->handle($account);

    expect($account->fresh()->access_token)->toBe('new')
        ->and($account->fresh()->needsReauth())->toBeFalse();
});

it('flags needs_reauth and notifies when refresh fails', function () {
    Notification::fake();
    $account = LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Twitch]);
    fakeLinkedAccounts()->willFailRefresh(LinkedAccountProvider::Twitch);

    app(RefreshLinkedAccountToken::class)->handle($account);

    expect($account->fresh()->needsReauth())->toBeTrue();
    Notification::assertSentTo($account->user, LinkedAccountReauthRequired::class);
});
