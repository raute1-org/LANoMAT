<?php

declare(strict_types=1);

use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Voice\Support\VoiceOccupancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds and reports occupant counts on a fake voice client', function () {
    $fake = fakeMumble();

    $channel = $fake->createChannel('Team Alpha');
    expect($channel->occupants)->toBe(0);

    $fake->setOccupants($channel->id, 3);

    $listed = $fake->listChannels();
    expect($listed)->toHaveCount(1)
        ->and($listed[0]->id)->toBe($channel->id)
        ->and($listed[0]->occupants)->toBe(3);
});

it('keeps other channel fields intact when seeding occupants', function () {
    $fake = fakeMumble();

    $channel = $fake->createChannel('sub-room', null, true);
    $fake->setOccupants($channel->id, 2);

    $updated = $fake->listChannels()[0];
    expect($updated->name)->toBe('sub-room')
        ->and($updated->temporary)->toBeTrue()
        ->and($updated->occupants)->toBe(2);
});

it('is a no-op when seeding occupants for an unknown channel id', function () {
    $fake = fakeMumble();

    $fake->setOccupants(999, 5);

    expect($fake->listChannels())->toBeEmpty();
});

it('aggregates occupant counts per channel across active providers for a tournament', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = Tournament::factory()->live()->create();

    $mumbleTournamentChannel = $fakes['mumble']->createChannel('🏆 Cup');
    $mumbleTeamChannel = $fakes['mumble']->createChannel('Team Alpha', $mumbleTournamentChannel->id);
    $teamspeakTournamentChannel = $fakes['teamspeak']->createChannel('🏆 Cup');
    $teamspeakTeamChannel = $fakes['teamspeak']->createChannel('Team Alpha', $teamspeakTournamentChannel->id);

    $fakes['mumble']->setOccupants($mumbleTournamentChannel->id, 4);
    $fakes['mumble']->setOccupants($mumbleTeamChannel->id, 2);
    $fakes['teamspeak']->setOccupants($teamspeakTournamentChannel->id, 1);

    $tournament->update([
        'settings' => [
            'voice' => [
                'mumble' => [
                    'tournament_channel_id' => $mumbleTournamentChannel->id,
                    'team_channel_ids' => [$mumbleTeamChannel->id],
                ],
                'teamspeak' => [
                    'tournament_channel_id' => $teamspeakTournamentChannel->id,
                    'team_channel_ids' => [$teamspeakTeamChannel->id],
                ],
            ],
        ],
    ]);

    $occupancy = VoiceOccupancy::forTournament($tournament->fresh());

    expect($occupancy['mumble'][$mumbleTournamentChannel->id])->toBe(4)
        ->and($occupancy['mumble'][$mumbleTeamChannel->id])->toBe(2)
        ->and($occupancy['teamspeak'][$teamspeakTournamentChannel->id])->toBe(1)
        ->and($occupancy['teamspeak'][$teamspeakTeamChannel->id])->toBe(0);
});

it('aggregates per-match voice channel occupants too', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = Tournament::factory()->live()->create();
    $entry1Channel = $fakes['mumble']->createChannel('Team Alpha', null, true);
    $entry2Channel = $fakes['mumble']->createChannel('Team Bravo', null, true);
    $fakes['mumble']->setOccupants($entry1Channel->id, 5);

    $match = GameMatch::factory()->for($tournament)->create([
        'voice_channels' => [
            'mumble' => [
                'entry1_channel_id' => $entry1Channel->id,
                'entry2_channel_id' => $entry2Channel->id,
            ],
        ],
    ]);

    $occupancy = VoiceOccupancy::forMatch($match->fresh());

    expect($occupancy['mumble'][$entry1Channel->id])->toBe(5)
        ->and($occupancy['mumble'][$entry2Channel->id])->toBe(0);
});

it('returns an empty occupancy map when a tournament has no voice settings', function () {
    fakeVoice(['mumble', 'teamspeak']);

    $tournament = Tournament::factory()->live()->create();

    expect(VoiceOccupancy::forTournament($tournament))->toBe([]);
});

it('returns an empty occupancy map when a match has no voice channels', function () {
    fakeVoice(['mumble', 'teamspeak']);

    $match = GameMatch::factory()->create(['voice_channels' => null]);

    expect(VoiceOccupancy::forMatch($match))->toBe([]);
});
