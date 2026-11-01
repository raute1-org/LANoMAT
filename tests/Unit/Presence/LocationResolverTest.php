<?php

declare(strict_types=1);

use App\Modules\Identity\Support\SteamActivity;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Domain\ServerRoster;
use App\Modules\Presence\Domain\VoiceSighting;
use App\Modules\Presence\Support\LocationResolver;

const LR_NOW = '2026-11-04T20:00:00+01:00';

function lrSighting(string $provider, string $room, ?int $userId = null, ?string $name = null, string $kind = 'other'): VoiceSighting
{
    return new VoiceSighting($provider, $room, 'Room '.$room, $kind, $userId, $name);
}

it('maps an exact id straight to the user, in several providers at once', function () {
    $people = LocationResolver::resolve(
        [lrSighting('discord', 'd1', userId: 7), lrSighting('webvoice', '3', userId: 7, kind: 'match')],
        [], [], [], [], LR_NOW,
    );

    expect($people)->toHaveCount(1)
        ->and(array_map(fn ($v) => $v->provider, $people[7]->voice))->toBe(['discord', 'webvoice'])
        ->and($people[7]->voice[1]->kind)->toBe('match')
        ->and($people[7]->voice[0]->since)->toBe(LR_NOW)
        ->and($people[7]->game)->toBeNull();
});

it('turns a voice name into a user only when exactly one user can have that name', function () {
    $index = ['mumble' => ['ada' => [1], 'sam' => [2, 3]]];

    $people = LocationResolver::resolve(
        [
            lrSighting('mumble', '5', name: 'Ada'),          // unique: Ada is user 1 (case-insensitive)
            lrSighting('mumble', '5', name: 'Sam'),          // two users are called Sam: unknown
            lrSighting('mumble', '5', name: 'Nobody'),       // nobody has that name: unknown
        ],
        $index, [], [], [], LR_NOW,
    );

    expect(array_keys($people))->toBe([1]);
});

it('leaves a name unknown when two occupants of the same provider carry it', function () {
    $people = LocationResolver::resolve(
        [lrSighting('teamspeak', '1', name: 'Ada'), lrSighting('teamspeak', '2', name: 'ada')],
        ['teamspeak' => ['ada' => [1]]], [], [], [], LR_NOW,
    );

    expect($people)->toBe([]);
});

it('never lets a name from one provider use another provider\'s index', function () {
    $people = LocationResolver::resolve(
        [lrSighting('teamspeak', '1', name: 'Ada')],
        ['mumble' => ['ada' => [1]]], [], [], [], LR_NOW,
    );

    expect($people)->toBe([]);
});

it('finds a player in the player list of one of our servers only when the name is unambiguous', function () {
    $roster = new ServerRoster(11, 'Minecraft', 'LAN Survival', '203.0.113.9', 25565, ['Ada', 'Twin', 'Twin', 'Ghost']);

    $people = LocationResolver::resolve([], [], [], [$roster], ['ada' => [1], 'twin' => [2], 'ghost' => [3, 4]], LR_NOW);

    expect(array_keys($people))->toBe([1])
        ->and($people[1]->game)->toBeInstanceOf(GameLocation::class)
        ->and($people[1]->game->source)->toBe('query')
        ->and($people[1]->game->linkId)->toBe(11)
        ->and($people[1]->game->serverTitle)->toBe('LAN Survival')
        ->and($people[1]->game->port)->toBe(25565);
});

it('uses the steam status for the game and the server when steam names an address', function () {
    $people = LocationResolver::resolve([], [], [
        5 => new SteamActivity('730', 'Counter-Strike 2', '198.51.100.7:27015'),
    ], [], [], LR_NOW);

    expect($people[5]->game->source)->toBe('steam')
        ->and($people[5]->game->game)->toBe('Counter-Strike 2')
        ->and($people[5]->game->address)->toBe('198.51.100.7')
        ->and($people[5]->game->port)->toBe(27015)
        ->and($people[5]->game->linkId)->toBeNull();
});

it('prefers our own server link when steam says the person is on it', function () {
    $roster = new ServerRoster(4, 'Counter-Strike 2', 'Match 12', '198.51.100.7', 27015);

    $people = LocationResolver::resolve([], [], [
        5 => new SteamActivity('730', 'Counter-Strike 2', '198.51.100.7:27015'),
    ], [$roster], [], LR_NOW);

    expect($people[5]->game->source)->toBe('steam')
        ->and($people[5]->game->linkId)->toBe(4)
        ->and($people[5]->game->serverTitle)->toBe('Match 12');
});

it('prefers the server link known to us when both the player list and steam know the person', function () {
    $roster = new ServerRoster(4, 'Garry\'s Mod', 'TTT', '198.51.100.7', 27015, ['Ada']);

    $people = LocationResolver::resolve([], [], [
        1 => new SteamActivity('4000', 'Garry\'s Mod'),
    ], [$roster], ['ada' => [1]], LR_NOW);

    expect($people[1]->game->source)->toBe('query')
        ->and($people[1]->game->linkId)->toBe(4);
});

it('keeps a steam address that does not look like ip and port out of the location', function () {
    $people = LocationResolver::resolve([], [], [
        5 => new SteamActivity('730', 'CS2', 'not-an-address'),
    ], [], [], LR_NOW);

    expect($people[5]->game->address)->toBeNull()->and($people[5]->game->port)->toBeNull();
});

it('round trips a person through the stored array', function () {
    $people = LocationResolver::resolve(
        [lrSighting('webvoice', '3', userId: 7, kind: 'team')],
        [], [7 => new SteamActivity('730', 'CS2', '198.51.100.7:27015')], [], [], LR_NOW,
    );

    $again = PersonLocation::fromArray($people[7]->toArray());

    expect($again->toArray())->toBe($people[7]->toArray());
});

it('normalizes names for comparison', function () {
    expect(LocationResolver::normalize('  ÄDA   Lovelace '))->toBe('äda lovelace');
});
