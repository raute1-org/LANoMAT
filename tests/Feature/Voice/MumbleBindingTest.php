<?php

use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\Testing\FakeVoiceClient;

it('resolves VoiceClient from the container as HttpMumbleClient by default', function () {
    $client = app(VoiceClient::class);

    expect($client)->toBeInstanceOf(HttpMumbleClient::class);
});

it('swaps VoiceClient binding using fakeMumble helper', function () {
    $fake = fakeMumble();

    $client = app(VoiceClient::class);

    expect($client)->toBe($fake)
        ->and($client)->toBeInstanceOf(FakeVoiceClient::class);
});

it('fakeMumble helper returns the same instance from the container', function () {
    $fake = fakeMumble();
    $fromContainer = app(VoiceClient::class);

    expect($fromContainer)->toBe($fake);
});
