<?php

use App\Modules\Voice\Contracts\MumbleClient;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\Testing\FakeMumbleClient;

it('resolves MumbleClient from the container as HttpMumbleClient by default', function () {
    $client = app(MumbleClient::class);

    expect($client)->toBeInstanceOf(HttpMumbleClient::class);
});

it('swaps MumbleClient binding using fakeMumble helper', function () {
    $fake = fakeMumble();

    $client = app(MumbleClient::class);

    expect($client)->toBe($fake)
        ->and($client)->toBeInstanceOf(FakeMumbleClient::class);
});

it('fakeMumble helper returns the same instance from the container', function () {
    $fake = fakeMumble();
    $fromContainer = app(MumbleClient::class);

    expect($fromContainer)->toBe($fake);
});
