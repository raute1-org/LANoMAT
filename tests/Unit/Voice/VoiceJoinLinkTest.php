<?php

declare(strict_types=1);

use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Support\VoiceJoinLink;

beforeEach(fn () => config([
    'services.mumble.host' => 'voice.lan', 'services.mumble.port' => 64738,
    'services.teamspeak.host' => 'voice.lan', 'services.teamspeak.port' => 9987,
    'services.voice.default_provider' => 'mumble',
]));

it('builds a mumble deep link', function () {
    expect(VoiceJoinLink::for(VoiceProvider::Mumble, 'Team Alpha'))
        ->toBe('mumble://voice.lan:64738/Team Alpha');
});

it('builds a ts3server connect link with port and channel', function () {
    expect(VoiceJoinLink::for(VoiceProvider::TeamSpeak, 'Team Alpha'))
        ->toBe('ts3server://voice.lan?port=9987&channel=Team%20Alpha');
});

it('falls back to the configured default provider when a team has no choice', function () {
    expect(VoiceJoinLink::defaultProviderFor(null))->toBe(VoiceProvider::Mumble);
    expect(VoiceJoinLink::defaultProviderFor(VoiceProvider::TeamSpeak))->toBe(VoiceProvider::TeamSpeak);
});
