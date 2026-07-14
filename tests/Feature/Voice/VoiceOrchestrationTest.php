<?php

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Voice\Domain\MumbleChannel;
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

it('provisions the tournament voice tree with a team channel per entry on TournamentStarted', function () {
    $fake = fakeMumble();

    $tournament = teamTournamentWithEntries();

    event(new TournamentStarted($tournament));

    $fake->assertChannelCreated('🏆 Valorant Cup');
    $fake->assertChannelCreated('Team Alpha');
    $fake->assertChannelCreated('Team Bravo');

    $settings = $tournament->fresh()->settings;
    expect($settings['voice']['tournament_channel_id'])->toBeInt();
    expect($settings['voice']['team_channel_ids'])->toHaveCount(2);
});

it('is idempotent when TournamentStarted fires twice for the same tournament', function () {
    $fake = fakeMumble();

    $tournament = teamTournamentWithEntries();

    event(new TournamentStarted($tournament));
    event(new TournamentStarted($tournament));

    expect(collect($fake->channels))->toHaveCount(3); // root + 2 team channels, not 6
});

it('creates temporary per-match team channels and stores their ids on MatchReady', function () {
    $fake = fakeMumble();
    fakeDiscord(); // MatchReady also triggers Task 18's Discord match-channel listener

    $tournament = teamTournamentWithEntries();
    [$entry1, $entry2] = $tournament->entries()->get();

    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'status' => MatchStatus::Ready,
    ]);

    event(new MatchReady($match));

    $channel1 = collect($fake->channels)->firstWhere('name', 'Team Alpha');
    $channel2 = collect($fake->channels)->firstWhere('name', 'Team Bravo');

    expect($channel1)->not->toBeNull();
    expect($channel2)->not->toBeNull();
    expect($channel1->temporary)->toBeTrue();
    expect($channel2->temporary)->toBeTrue();

    $voiceChannels = $match->fresh()->voice_channels;
    expect($voiceChannels['entry1_channel_id'])->toBe($channel1->id);
    expect($voiceChannels['entry2_channel_id'])->toBe($channel2->id);
});

it('is idempotent when MatchReady fires twice for the same match', function () {
    $fake = fakeMumble();
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

    expect(collect($fake->channels))->toHaveCount(2);
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

it('explicitly deletes the tournament channel tree and match channels on TournamentCompleted, clearing stored ids', function () {
    // Only fake the job itself, since the listener is queued and a blanket
    // Bus::fake() would swallow the queued-listener dispatch too (mirrors
    // Task 18's MatchChannelTest handling of CleanupMatchChannelJob).
    Bus::fake([CleanupTournamentVoiceJob::class]);

    $fake = fakeMumble();

    $tournament = teamTournamentWithEntries();
    $tournamentChannel = $fake->createChannel('🏆 Valorant Cup');
    $teamChannel1 = $fake->createChannel('Team Alpha', $tournamentChannel->id);
    $teamChannel2 = $fake->createChannel('Team Bravo', $tournamentChannel->id);

    $tournament->update([
        'settings' => [
            'voice' => [
                'tournament_channel_id' => $tournamentChannel->id,
                'team_channel_ids' => [$teamChannel1->id, $teamChannel2->id],
            ],
        ],
        'status' => TournamentStatus::Finished,
    ]);

    $matchChannel1 = $fake->createChannel('Team Alpha (match)', null, true);
    $matchChannel2 = $fake->createChannel('Team Bravo (match)', null, true);

    $match = GameMatch::factory()->for($tournament)->create([
        'status' => MatchStatus::Completed,
        'voice_channels' => [
            'entry1_channel_id' => $matchChannel1->id,
            'entry2_channel_id' => $matchChannel2->id,
        ],
    ]);

    event(new TournamentCompleted($tournament));

    Bus::assertDispatched(CleanupTournamentVoiceJob::class, fn (CleanupTournamentVoiceJob $job) => $job->tournamentId === $tournament->id);

    (new CleanupTournamentVoiceJob($tournament->id))->handle($fake);

    $fake->assertChannelDeleted($tournamentChannel->id);
    $fake->assertChannelDeleted($teamChannel1->id);
    $fake->assertChannelDeleted($teamChannel2->id);
    $fake->assertChannelDeleted($matchChannel1->id);
    $fake->assertChannelDeleted($matchChannel2->id);

    expect($tournament->fresh()->settings['voice'] ?? null)->toBeNull();
    expect($match->fresh()->voice_channels)->toBeNull();
});

it('builds a mumble:// join link from a channel id/path and config', function () {
    expect(MumbleJoinLink::for('Team Alpha'))->toBe('mumble://voice.example.test:64738/Team Alpha');

    $channel = new MumbleChannel(42, 'Team Bravo', null, false);
    expect(MumbleJoinLink::for($channel))->toBe('mumble://voice.example.test:64738/Team Bravo');
});

it('embeds a resolved German join-voice label on the tournament show page for the viewer own match', function () {
    app()->setLocale('de');

    expect(__('tournaments.page.join_voice'))->toBe('Voice beitreten');
});
