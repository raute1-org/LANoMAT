<?php

use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\HttpTeamSpeakClient;
use App\Modules\Voice\Support\VoiceJoinLink;
use App\Modules\Voice\Testing\FakeVoiceClient;
use Illuminate\Support\Facades\Http;

it('reads the occupant names a Mumble sidecar reports and defaults to none', function () {
    Http::fake([
        'mumble-admin.test/channels' => Http::response([
            ['id' => 1, 'name' => 'Lobby', 'parent' => 0, 'temporary' => false, 'occupants' => 2, 'occupant_names' => ['Ada', 'Bea']],
            ['id' => 2, 'name' => 'Old sidecar', 'parent' => 0, 'temporary' => false, 'occupants' => 1],
            ['id' => 3, 'name' => 'Odd', 'parent' => 0, 'temporary' => false, 'occupants' => 1, 'occupant_names' => 'not-a-list'],
        ]),
    ]);

    $channels = (new HttpMumbleClient('http://mumble-admin.test', 'secret'))->listChannels();

    expect($channels[0]->occupantNames)->toBe(['Ada', 'Bea'])
        ->and($channels[1]->occupantNames)->toBe([])
        ->and($channels[2]->occupantNames)->toBe([]);
});

it('reads the occupant names a TeamSpeak sidecar reports and defaults to none', function () {
    Http::fake([
        'ts-admin.test/channels' => Http::response([
            ['id' => 1, 'name' => 'Lobby', 'parent' => 0, 'temporary' => false, 'occupants' => 1, 'occupant_names' => ['Cem']],
            ['id' => 2, 'name' => 'Old sidecar', 'parent' => 0, 'temporary' => false, 'occupants' => 0],
        ]),
    ]);

    $channels = (new HttpTeamSpeakClient('http://ts-admin.test', 'secret'))->listChannels();

    expect($channels[0]->occupantNames)->toBe(['Cem'])->and($channels[1]->occupantNames)->toBe([]);
});

it('lets the fake report names and keeps them through a rename', function () {
    $fake = new FakeVoiceClient(VoiceProvider::TeamSpeak);
    $channel = $fake->createChannel('Lobby');

    $fake->setOccupantNames($channel->id, ['Ada', 'Bea']);
    $fake->renameChannel($channel->id, 'Lounge');

    $listed = $fake->listChannels()[0];
    expect($listed)->toBeInstanceOf(VoiceChannel::class)
        ->and($listed->name)->toBe('Lounge')
        ->and($listed->occupants)->toBe(2)
        ->and($listed->occupantNames)->toBe(['Ada', 'Bea']);

    $fake->setOccupants($channel->id, 5);
    expect($fake->listChannels()[0]->occupantNames)->toBe(['Ada', 'Bea']);
});

it('names a person on a server the way the join link does', function () {
    expect(VoiceJoinLink::serverNameFor(VoiceProvider::Mumble, '  Ada <b>L</b>  '))->toBe('Ada bLb')
        ->and(VoiceJoinLink::serverNameFor(VoiceProvider::Mumble, 'Al'))->toBe('Al')
        ->and(VoiceJoinLink::serverNameFor(VoiceProvider::TeamSpeak, 'Al'))->toBeNull()
        ->and(VoiceJoinLink::serverNameFor(VoiceProvider::Mumble, str_repeat('x', 40)))->toBe(str_repeat('x', 30))
        ->and(VoiceJoinLink::serverNameFor(VoiceProvider::Mumble, '###'))->toBeNull();
});
