<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Support\LinkedAccountData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a Steam account for the authenticated user', function () {
    fakeLinkedAccounts()->willResolve(LinkedAccountProvider::Steam, new LinkedAccountData(
        provider_user_id: '76561198000000000', nickname: 'FraggerX',
        access_token: null, refresh_token: null, token_expires_at: null,
    ));
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections/steam/callback')->assertRedirect('/settings/connections');

    $account = $user->linkedAccount(LinkedAccountProvider::Steam);
    expect($account->provider_user_id)->toBe('76561198000000000')
        ->and($account->nickname)->toBe('FraggerX')
        ->and($account->access_token)->toBeNull();       // Steam OpenID: no token
});

it('refuses to link a Steam account already owned by another user', function () {
    LinkedAccount::factory()->create(['provider' => LinkedAccountProvider::Steam, 'provider_user_id' => '765']);
    fakeLinkedAccounts()->willResolve(LinkedAccountProvider::Steam, new LinkedAccountData(
        provider_user_id: '765', nickname: null,
        access_token: null, refresh_token: null, token_expires_at: null,
    ));
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections/steam/callback')
        ->assertRedirect()->assertSessionHas('errors');
    expect($user->linkedAccount(LinkedAccountProvider::Steam))->toBeNull();
});

it('unlinks only the caller\'s own account (policy)', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => LinkedAccountProvider::Steam]);

    $this->actingAs($user)->delete('/settings/connections/steam')->assertRedirect();
    expect($user->fresh()->linkedAccount(LinkedAccountProvider::Steam))->toBeNull();
});
