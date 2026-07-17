<?php

declare(strict_types=1);

use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\HttpTeamSpeakClient;
use App\Modules\Voice\VoiceProviders;

it('resolves only the providers listed as active, keyed by value', function () {
    config(['services.voice.providers' => ['mumble', 'teamspeak']]);

    $active = app(VoiceProviders::class)->active();

    expect(array_keys($active))->toBe(['mumble', 'teamspeak'])
        ->and($active['mumble'])->toBeInstanceOf(HttpMumbleClient::class)
        ->and($active['teamspeak'])->toBeInstanceOf(HttpTeamSpeakClient::class);
});

it('resolves a single provider when only one is active', function () {
    config(['services.voice.providers' => ['mumble']]);

    $active = app(VoiceProviders::class)->active();

    expect(array_keys($active))->toBe(['mumble']);
});

it('returns the concrete client for a given provider', function () {
    expect(app(VoiceProviders::class)->for(VoiceProvider::TeamSpeak))
        ->toBeInstanceOf(VoiceClient::class);
});
