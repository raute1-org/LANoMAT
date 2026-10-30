<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders linked + unlinked providers without leaking tokens', function () {
    config()->set('services.steam.client_secret', 'k');
    config()->set('services.twitch.client_id', 'k');
    config()->set('services.twitch.client_secret', 'k');
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create([
        'provider' => LinkedAccountProvider::Twitch, 'nickname' => 'streamer1', 'access_token' => 'secret',
    ]);

    $this->actingAs($user)->get('/settings/connections')
        ->assertInertia(fn ($p) => $p
            ->component('settings/Connections')
            ->where('providers.1.linked', true)
            ->where('providers.1.nickname', 'streamer1')
            ->missing('providers.1.access_token'));
});

it('shows the unlinked state with a redirect url for a provider with no linked account', function () {
    config()->set('services.steam.client_secret', 'k');
    config()->set('services.twitch.client_id', 'k');
    config()->set('services.twitch.client_secret', 'k');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections')
        ->assertInertia(fn ($p) => $p
            ->component('settings/Connections')
            ->where('providers.0.provider', LinkedAccountProvider::Steam->value)
            ->where('providers.0.linked', false)
            ->where('providers.0.nickname', null)
            ->where('providers.0.needsReauth', false)
            ->has('providers.0.redirectUrl')
            ->has('providers.0.unlinkUrl'));
});

it('flags needsReauth on a linked account that requires re-authorization', function () {
    config()->set('services.twitch.client_id', 'k');
    config()->set('services.twitch.client_secret', 'k');
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create([
        'provider' => LinkedAccountProvider::Twitch,
        'nickname' => 'streamer1',
        'meta' => ['needs_reauth' => true],
    ]);

    $this->actingAs($user)->get('/settings/connections')
        ->assertInertia(fn ($p) => $p
            ->component('settings/Connections')
            ->where('providers.1.provider', LinkedAccountProvider::Twitch->value)
            ->where('providers.1.needsReauth', true));
});

it('lists unconfigured providers as unavailable instead of hiding them', function () {
    config()->set('services.steam.client_secret', null);
    config()->set('services.twitch.client_id', null);
    config()->set('services.twitch.client_secret', null);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections')
        ->assertInertia(fn ($p) => $p
            ->component('settings/Connections')
            ->has('providers', 2)
            ->where('providers.0.available', false)
            ->where('providers.1.available', false)
            ->where('providers.0.linked', false));
});

it('keeps a linked account of an unconfigured provider listed so it can be unlinked', function () {
    config()->set('services.twitch.client_id', null);
    config()->set('services.twitch.client_secret', null);
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create([
        'provider' => LinkedAccountProvider::Twitch, 'nickname' => 'streamer1',
    ]);

    $this->actingAs($user)->get('/settings/connections')
        ->assertInertia(fn ($p) => $p
            ->where('providers.1.provider', 'twitch')
            ->where('providers.1.available', false)
            ->where('providers.1.linked', true));

    $this->actingAs($user)->delete('/settings/connections/twitch')->assertRedirect();
    expect($user->fresh()->linkedAccount(LinkedAccountProvider::Twitch))->toBeNull();
});

it('answers 404 on the redirect and callback of an unconfigured provider', function () {
    config()->set('services.steam.client_secret', null);
    config()->set('services.twitch.client_id', null);
    config()->set('services.twitch.client_secret', null);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/connections/steam/redirect')->assertNotFound();
    $this->actingAs($user)->get('/settings/connections/twitch/redirect')->assertNotFound();
    $this->actingAs($user)->get('/settings/connections/twitch/callback')->assertNotFound();
});
