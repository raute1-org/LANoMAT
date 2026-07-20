<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a Twitch account for the authenticated user, storing OAuth tokens', function () {
    fakeLinkedAccounts()->willResolve(LinkedAccountProvider::Twitch, new LinkedAccountData(
        provider_user_id: '123456789', nickname: 'StreamerX',
        access_token: 'access-token', refresh_token: 'refresh-token',
        token_expires_at: now()->addHours(4),
    ));
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections/twitch/callback')->assertRedirect('/settings/connections');

    $account = $user->linkedAccount(LinkedAccountProvider::Twitch);
    expect($account->provider_user_id)->toBe('123456789')
        ->and($account->nickname)->toBe('StreamerX')
        ->and($account->access_token)->toBe('access-token')
        ->and($account->refresh_token)->toBe('refresh-token')
        ->and($account->token_expires_at)->not->toBeNull();
});

it('refuses to link a Twitch account already owned by another user', function () {
    LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Twitch, 'provider_user_id' => '999']);
    fakeLinkedAccounts()->willResolve(LinkedAccountProvider::Twitch, new LinkedAccountData(
        provider_user_id: '999', nickname: null,
        access_token: 'access-token', refresh_token: 'refresh-token',
        token_expires_at: now()->addHours(4),
    ));
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections/twitch/callback')
        ->assertRedirect()->assertSessionHas('errors');
    expect($user->linkedAccount(LinkedAccountProvider::Twitch))->toBeNull();
});

it('unlinks only the caller\'s own Twitch account (policy)', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Twitch]);

    $this->actingAs($user)->delete('/settings/connections/twitch')->assertRedirect();
    expect($user->fresh()->linkedAccount(LinkedAccountProvider::Twitch))->toBeNull();
});
