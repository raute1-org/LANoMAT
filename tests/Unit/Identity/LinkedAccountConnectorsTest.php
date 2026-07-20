<?php

declare(strict_types=1);

use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves a connector per provider and lists only configured ones', function () {
    config()->set('services.steam.client_secret', 'key');   // configured
    config()->set('services.twitch.client_id', null);        // NOT configured
    $registry = app(LinkedAccountConnectors::class);

    expect($registry->enabled())->toContain(LinkedAccountProvider::Steam)
        ->not->toContain(LinkedAccountProvider::Twitch);
    expect($registry->for(LinkedAccountProvider::Steam)->provider())->toBe(LinkedAccountProvider::Steam);
});
