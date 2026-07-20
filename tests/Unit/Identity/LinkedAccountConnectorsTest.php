<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only configured providers as enabled, config-driven with no connector bound', function () {
    config()->set('services.steam.client_secret', 'key');   // configured
    config()->set('services.twitch.client_id', null);        // NOT configured
    $registry = app(LinkedAccountConnectors::class);

    expect($registry->enabled())->toContain(LinkedAccountProvider::Steam)
        ->not->toContain(LinkedAccountProvider::Twitch);
});

it('resolves a connector per provider once a fake is bound', function () {
    fakeLinkedAccounts();
    $registry = app(LinkedAccountConnectors::class);

    expect($registry->for(LinkedAccountProvider::Steam)->provider())->toBe(LinkedAccountProvider::Steam);
});

it('throws when no connector is bound for a provider', function () {
    // Steam has a real connector bound in production since Task 9.3; Twitch
    // remains unbound until Task 9.4, so it still exercises this path.
    $registry = app(LinkedAccountConnectors::class);

    expect(fn () => $registry->for(LinkedAccountProvider::Twitch))
        ->toThrow(IdentityException::class);
});
