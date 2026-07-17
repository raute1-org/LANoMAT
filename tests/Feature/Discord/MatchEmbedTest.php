<?php

declare(strict_types=1);

use App\Modules\Discord\Support\MatchEmbed;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.mumble.host' => 'voice.example.test',
        'services.mumble.port' => 64738,
        'services.teamspeak.host' => 'ts.example.test',
        'services.teamspeak.port' => 9987,
        'services.voice.default_provider' => 'mumble',
    ]);
});

function makeMatchWithVoiceChannels(array $voiceChannels): GameMatch
{
    $event = Event::factory()->live()->create();
    $tournament = Tournament::factory()->for($event)->live()->create();
    $entry1 = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id, 'display_name' => 'Team Alpha']);
    $entry2 = TournamentEntry::factory()->checkedIn()->create(['tournament_id' => $tournament->id, 'display_name' => 'Team Bravo']);

    return GameMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'voice_channels' => $voiceChannels,
    ]);
}

it('lists one voice line per provider present, for both entries', function () {
    $match = makeMatchWithVoiceChannels([
        'mumble' => ['entry1_channel_id' => 1, 'entry2_channel_id' => 2],
        'teamspeak' => ['entry1_channel_id' => 3, 'entry2_channel_id' => 4],
    ]);

    $voiceLink = MatchEmbed::voiceLink($match, 'Team Alpha', 'Team Bravo');

    expect($voiceLink)
        ->not->toBeNull()
        ->and($voiceLink)->toContain('mumble://voice.example.test:64738/Team Alpha')
        ->and($voiceLink)->toContain('mumble://voice.example.test:64738/Team Bravo')
        ->and($voiceLink)->toContain('ts3server://ts.example.test?port=9987&channel=Team%20Alpha')
        ->and($voiceLink)->toContain('ts3server://ts.example.test?port=9987&channel=Team%20Bravo');
});

it('marks the config default provider line with the default marker', function () {
    $match = makeMatchWithVoiceChannels([
        'mumble' => ['entry1_channel_id' => 1, 'entry2_channel_id' => 2],
        'teamspeak' => ['entry1_channel_id' => 3, 'entry2_channel_id' => 4],
    ]);

    $voiceLink = MatchEmbed::voiceLink($match, 'Team Alpha', 'Team Bravo');

    $lines = explode("\n", $voiceLink);
    $mumbleHeadingIndex = array_key_first(array_filter($lines, fn (string $line): bool => str_contains($line, 'Mumble')));
    $teamspeakHeadingIndex = array_key_first(array_filter($lines, fn (string $line): bool => str_contains($line, 'TeamSpeak')));

    expect($lines[$mumbleHeadingIndex])->toContain(__('discord.match_channel.voice_default_marker', ['provider' => 'Mumble']))
        ->and($lines[$teamspeakHeadingIndex])->not->toContain('★')
        ->and($lines[$teamspeakHeadingIndex])->toBe('**TeamSpeak**');
});

it('returns null when no voice channels have been provisioned for any provider', function () {
    $match = makeMatchWithVoiceChannels([]);

    expect(MatchEmbed::voiceLink($match, 'Team Alpha', 'Team Bravo'))->toBeNull();
});

it('skips a provider entirely when neither entry has a channel id for it', function () {
    $match = makeMatchWithVoiceChannels([
        'mumble' => ['entry1_channel_id' => 1, 'entry2_channel_id' => 2],
        'teamspeak' => ['entry1_channel_id' => null, 'entry2_channel_id' => null],
    ]);

    $voiceLink = MatchEmbed::voiceLink($match, 'Team Alpha', 'Team Bravo');

    expect($voiceLink)->toContain('mumble://')
        ->and($voiceLink)->not->toContain('ts3server://');
});
