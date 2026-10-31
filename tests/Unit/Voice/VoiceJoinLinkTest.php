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

it('puts the username into a mumble link', function () {
    expect(VoiceJoinLink::for(VoiceProvider::Mumble, 'Team Alpha', 'Max Mustermann'))
        ->toBe('mumble://Max%20Mustermann@voice.lan:64738/Team Alpha');
});

it('puts the username into a ts3server link as the nickname', function () {
    expect(VoiceJoinLink::for(VoiceProvider::TeamSpeak, 'Team Alpha', 'Max Mustermann'))
        ->toBe('ts3server://voice.lan?port=9987&nickname=Max%20Mustermann&channel=Team%20Alpha');
});

it('leaves the username out when there is none', function () {
    expect(VoiceJoinLink::for(VoiceProvider::Mumble, 'Lobby', null))->toBe('mumble://voice.lan:64738/Lobby');
    expect(VoiceJoinLink::for(VoiceProvider::Mumble, 'Lobby', '   '))->toBe('mumble://voice.lan:64738/Lobby');
});

it('drops characters a voice server would refuse in a name and keeps umlauts', function () {
    expect(VoiceJoinLink::for(VoiceProvider::Mumble, 'Lobby', 'Jörg <b>Ü</b>:/\\#?'))
        ->toBe('mumble://J%C3%B6rg%20b%C3%9Cb@voice.lan:64738/Lobby');
});

it('shortens a long name to what TeamSpeak allows', function () {
    $link = VoiceJoinLink::for(VoiceProvider::TeamSpeak, 'Lobby', str_repeat('a', 60));

    expect($link)->toContain('nickname='.str_repeat('a', 30).'&');
});

it('skips a nickname that is too short for TeamSpeak', function () {
    expect(VoiceJoinLink::for(VoiceProvider::TeamSpeak, 'Lobby', 'Al'))->toBe('ts3server://voice.lan?port=9987&channel=Lobby');
});
