<?php

declare(strict_types=1);

use App\Modules\Voice\Domain\VoiceProvider;

it('exposes a stable string value and a German label for each provider', function () {
    expect(VoiceProvider::Mumble->value)->toBe('mumble')
        ->and(VoiceProvider::TeamSpeak->value)->toBe('teamspeak')
        ->and(VoiceProvider::Mumble->label())->toBe('Mumble')
        ->and(VoiceProvider::TeamSpeak->label())->toBe('TeamSpeak');
});

it('lists the providers marked active in config', function () {
    config(['services.voice.providers' => ['mumble', 'teamspeak']]);
    expect(VoiceProvider::active())->toBe([VoiceProvider::Mumble, VoiceProvider::TeamSpeak]);

    config(['services.voice.providers' => ['mumble']]);
    expect(VoiceProvider::active())->toBe([VoiceProvider::Mumble]);
});
