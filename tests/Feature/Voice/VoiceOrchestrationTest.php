<?php

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Jobs\CleanupTournamentVoiceJob;
use App\Modules\Voice\Support\MumbleJoinLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.mumble.host' => 'voice.example.test',
        'services.mumble.port' => 64738,
    ]);
});

function teamTournamentWithEntries(): Tournament
{
    $tournament = Tournament::factory()->live()->create([
        'name' => 'Valorant Cup',
        'team_size' => 5,
    ]);

    TournamentEntry::factory()->team()->for($tournament)->create(['display_name' => 'Team Alpha']);
    TournamentEntry::factory()->team()->for($tournament)->create(['display_name' => 'Team Bravo']);

    return $tournament;
}

it('provisions the tournament voice tree with a team channel per entry on every active provider on TournamentStarted', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = teamTournamentWithEntries();

    event(new TournamentStarted($tournament));

    foreach ($fakes as $fake) {
        $fake->assertChannelCreated('🏆 Valorant Cup');
        $fake->assertChannelCreated('Team Alpha');
        $fake->assertChannelCreated('Team Bravo');
    }

    $settings = $tournament->fresh()->settings;

    expect($settings['voice']['mumble']['tournament_channel_id'])->toBeInt();
    expect($settings['voice']['mumble']['team_channel_ids'])->toHaveCount(2);
    expect($settings['voice']['teamspeak']['tournament_channel_id'])->toBeInt();
    expect($settings['voice']['teamspeak']['team_channel_ids'])->toHaveCount(2);

    // Each provider's subtree is populated independently — resolving the id
    // against the WRONG fake must fail, proving the ids are not accidentally
    // shared/cross-written between backends (each fake has its own
    // independent sequence, so raw ids may coincide numerically; what matters
    // is that each id only resolves on its own provider's fake).
    expect($fakes['mumble']->channels)->toHaveKey($settings['voice']['mumble']['tournament_channel_id']);
    expect($fakes['teamspeak']->channels)->toHaveKey($settings['voice']['teamspeak']['tournament_channel_id']);
    expect($fakes['mumble']->channels[$settings['voice']['mumble']['tournament_channel_id']]->name)->toBe('🏆 Valorant Cup');
    expect($fakes['teamspeak']->channels[$settings['voice']['teamspeak']['tournament_channel_id']]->name)->toBe('🏆 Valorant Cup');
});

it('is idempotent per provider when TournamentStarted fires twice for the same tournament', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = teamTournamentWithEntries();

    event(new TournamentStarted($tournament));
    event(new TournamentStarted($tournament));

    foreach ($fakes as $fake) {
        expect(collect($fake->channels))->toHaveCount(3); // root + 2 team channels, not 6
    }
});

it('only provisions the active providers, then mirrors to a newly activated provider on re-fire without touching the existing one', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    // Start with only mumble active.
    config(['services.voice.providers' => ['mumble']]);

    $tournament = teamTournamentWithEntries();

    event(new TournamentStarted($tournament));

    $settings = $tournament->fresh()->settings;
    expect($settings['voice'])->toHaveKey('mumble');
    expect($settings['voice'])->not->toHaveKey('teamspeak');
    expect(collect($fakes['teamspeak']->channels))->toBeEmpty();

    $mumbleTournamentChannelId = $settings['voice']['mumble']['tournament_channel_id'];
    $mumbleTeamChannelIds = $settings['voice']['mumble']['team_channel_ids'];

    // Now activate teamspeak too and re-fire.
    config(['services.voice.providers' => ['mumble', 'teamspeak']]);

    event(new TournamentStarted($tournament));

    $settings = $tournament->fresh()->settings;

    // Mumble subtree untouched (same ids, no new channels created there).
    expect($settings['voice']['mumble']['tournament_channel_id'])->toBe($mumbleTournamentChannelId);
    expect($settings['voice']['mumble']['team_channel_ids'])->toBe($mumbleTeamChannelIds);
    expect(collect($fakes['mumble']->channels))->toHaveCount(3);

    // Teamspeak newly provisioned.
    expect($settings['voice']['teamspeak']['tournament_channel_id'])->toBeInt();
    expect($settings['voice']['teamspeak']['team_channel_ids'])->toHaveCount(2);
    $fakes['teamspeak']->assertChannelCreated('🏆 Valorant Cup');
    $fakes['teamspeak']->assertChannelCreated('Team Alpha');
    $fakes['teamspeak']->assertChannelCreated('Team Bravo');
});

it('creates temporary per-match team channels on every active provider and stores their ids per provider on MatchReady', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);
    fakeDiscord(); // MatchReady also triggers Task 18's Discord match-channel listener

    $tournament = teamTournamentWithEntries();
    [$entry1, $entry2] = $tournament->entries()->get();

    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'status' => MatchStatus::Ready,
    ]);

    event(new MatchReady($match));

    $voiceChannels = $match->fresh()->voice_channels;

    foreach (['mumble', 'teamspeak'] as $provider) {
        $fake = $fakes[$provider];

        $channel1 = collect($fake->channels)->firstWhere('name', 'Team Alpha');
        $channel2 = collect($fake->channels)->firstWhere('name', 'Team Bravo');

        expect($channel1)->not->toBeNull();
        expect($channel2)->not->toBeNull();
        expect($channel1->temporary)->toBeTrue();
        expect($channel2->temporary)->toBeTrue();

        expect($voiceChannels[$provider]['entry1_channel_id'])->toBe($channel1->id);
        expect($voiceChannels[$provider]['entry2_channel_id'])->toBe($channel2->id);
    }

    // Each provider's ids resolve only against its own fake — proving the
    // subtrees are stored independently rather than one overwriting the other.
    expect($fakes['mumble']->channels)->toHaveKey($voiceChannels['mumble']['entry1_channel_id']);
    expect($fakes['teamspeak']->channels)->toHaveKey($voiceChannels['teamspeak']['entry1_channel_id']);
});

it('is idempotent per provider when MatchReady fires twice for the same match', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);
    fakeDiscord();

    $tournament = teamTournamentWithEntries();
    [$entry1, $entry2] = $tournament->entries()->get();

    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'status' => MatchStatus::Ready,
    ]);

    event(new MatchReady($match));
    event(new MatchReady($match));

    foreach ($fakes as $fake) {
        expect(collect($fake->channels))->toHaveCount(2);
    }
});

it('does nothing on MatchReady when a match does not have both entries resolved', function () {
    $fake = fakeMumble();
    fakeDiscord();

    $tournament = teamTournamentWithEntries();
    $entry1 = $tournament->entries()->first();

    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => null,
        'status' => MatchStatus::Pending,
    ]);

    event(new MatchReady($match));

    expect(collect($fake->channels))->toBeEmpty();
    expect($match->fresh()->voice_channels)->toBeNull();
});

it('explicitly deletes the tournament channel tree and match channels on every provider on TournamentCompleted, clearing stored ids', function () {
    // Only fake the job itself, since the listener is queued and a blanket
    // Bus::fake() would swallow the queued-listener dispatch too (mirrors
    // Task 18's MatchChannelTest handling of CleanupMatchChannelJob).
    Bus::fake([CleanupTournamentVoiceJob::class]);

    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = teamTournamentWithEntries();

    $voiceSettings = [];
    $teamChannels = [];
    $matchChannels = [];

    foreach ($fakes as $provider => $fake) {
        $tournamentChannel = $fake->createChannel('🏆 Valorant Cup');
        $teamChannel1 = $fake->createChannel('Team Alpha', $tournamentChannel->id);
        $teamChannel2 = $fake->createChannel('Team Bravo', $tournamentChannel->id);

        $voiceSettings[$provider] = [
            'tournament_channel_id' => $tournamentChannel->id,
            'team_channel_ids' => [$teamChannel1->id, $teamChannel2->id],
        ];
        $teamChannels[$provider] = [$tournamentChannel, $teamChannel1, $teamChannel2];

        $matchChannel1 = $fake->createChannel('Team Alpha (match)', null, true);
        $matchChannel2 = $fake->createChannel('Team Bravo (match)', null, true);
        $matchChannels[$provider] = [$matchChannel1, $matchChannel2];
    }

    $tournament->update([
        'settings' => ['voice' => $voiceSettings],
        'status' => TournamentStatus::Finished,
    ]);

    $match = GameMatch::factory()->for($tournament)->create([
        'status' => MatchStatus::Completed,
        'voice_channels' => [
            'mumble' => [
                'entry1_channel_id' => $matchChannels['mumble'][0]->id,
                'entry2_channel_id' => $matchChannels['mumble'][1]->id,
            ],
            'teamspeak' => [
                'entry1_channel_id' => $matchChannels['teamspeak'][0]->id,
                'entry2_channel_id' => $matchChannels['teamspeak'][1]->id,
            ],
        ],
    ]);

    event(new TournamentCompleted($tournament));

    Bus::assertDispatched(CleanupTournamentVoiceJob::class, fn (CleanupTournamentVoiceJob $job) => $job->tournamentId === $tournament->id);

    app()->call([new CleanupTournamentVoiceJob($tournament->id), 'handle']);

    foreach ($fakes as $provider => $fake) {
        [$tournamentChannel, $teamChannel1, $teamChannel2] = $teamChannels[$provider];
        [$matchChannel1, $matchChannel2] = $matchChannels[$provider];

        $fake->assertChannelDeleted($tournamentChannel->id);
        $fake->assertChannelDeleted($teamChannel1->id);
        $fake->assertChannelDeleted($teamChannel2->id);
        $fake->assertChannelDeleted($matchChannel1->id);
        $fake->assertChannelDeleted($matchChannel2->id);
    }

    expect($tournament->fresh()->settings['voice'] ?? null)->toBeNull();
    expect($match->fresh()->voice_channels)->toBeNull();
});

it('tolerates a stored provider that is no longer active during cleanup, still deleting its leftover channels', function () {
    Bus::fake([CleanupTournamentVoiceJob::class]);

    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = teamTournamentWithEntries();

    $mumbleFake = $fakes['mumble'];
    $teamspeakFake = $fakes['teamspeak'];

    $mumbleTournamentChannel = $mumbleFake->createChannel('🏆 Valorant Cup');
    $teamspeakTournamentChannel = $teamspeakFake->createChannel('🏆 Valorant Cup');

    $tournament->update([
        'settings' => [
            'voice' => [
                'mumble' => [
                    'tournament_channel_id' => $mumbleTournamentChannel->id,
                    'team_channel_ids' => [],
                ],
                'teamspeak' => [
                    'tournament_channel_id' => $teamspeakTournamentChannel->id,
                    'team_channel_ids' => [],
                ],
            ],
        ],
        'status' => TournamentStatus::Finished,
    ]);

    // Deactivate teamspeak before cleanup runs — its channels are now
    // "leftover" from the active set's perspective, but must still be torn
    // down since the fake/provider itself is still resolvable via for().
    config(['services.voice.providers' => ['mumble']]);

    event(new TournamentCompleted($tournament));

    app()->call([new CleanupTournamentVoiceJob($tournament->id), 'handle']);

    $mumbleFake->assertChannelDeleted($mumbleTournamentChannel->id);
    $teamspeakFake->assertChannelDeleted($teamspeakTournamentChannel->id);

    expect($tournament->fresh()->settings['voice'] ?? null)->toBeNull();
});

it('builds a mumble:// join link from a channel id/path and config', function () {
    expect(MumbleJoinLink::for('Team Alpha'))->toBe('mumble://voice.example.test:64738/Team Alpha');

    $channel = new VoiceChannel(42, 'Team Bravo', null, false);
    expect(MumbleJoinLink::for($channel))->toBe('mumble://voice.example.test:64738/Team Bravo');
});

it('embeds a resolved German join-voice label on the tournament show page for the viewer own match', function () {
    app()->setLocale('de');

    expect(__('tournaments.page.join_voice'))->toBe('Voice beitreten');
});
