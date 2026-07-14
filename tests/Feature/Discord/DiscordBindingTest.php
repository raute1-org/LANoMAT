<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\HttpDiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;

it('resolves DiscordClient from the container as HttpDiscordClient by default', function () {
    $client = app(DiscordClient::class);

    expect($client)->toBeInstanceOf(HttpDiscordClient::class);
});

it('swaps DiscordClient binding using fakeDiscord helper', function () {
    $fake = fakeDiscord();

    $client = app(DiscordClient::class);

    expect($client)->toBe($fake)
        ->and($client)->toBeInstanceOf(FakeDiscordClient::class);
});

it('fakeDiscord helper returns the same instance from the container', function () {
    $fake = fakeDiscord();
    $fromContainer = app(DiscordClient::class);

    expect($fromContainer)->toBe($fake);
});
