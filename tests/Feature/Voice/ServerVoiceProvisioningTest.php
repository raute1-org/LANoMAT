<?php

declare(strict_types=1);

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Voice\Jobs\CleanupTournamentVoiceJob;
use App\Modules\Voice\Listeners\ProvisionServerVoiceOnReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function readyServerLinkForMatch(GameMatch $match): ServerLink
{
    return ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Ready,
    ]);
}

it('creates a shared per-server voice channel on every active provider when a ServerLink turns Ready, persisting the ids per provider on the match', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = Tournament::factory()->live()->create();
    $entry1 = TournamentEntry::factory()->for($tournament)->create(['display_name' => 'Team Alpha']);
    $entry2 = TournamentEntry::factory()->for($tournament)->create(['display_name' => 'Team Bravo']);
    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
        'status' => MatchStatus::Ready,
    ]);

    $link = readyServerLinkForMatch($match);

    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated($link));

    foreach ($fakes as $fake) {
        $fake->assertChannelCreated('🎮 Team Alpha vs Team Bravo');
    }

    $voiceChannels = $match->fresh()->voice_channels;

    expect($voiceChannels['mumble']['server_channel_id'])->toBeInt();
    expect($voiceChannels['teamspeak']['server_channel_id'])->toBeInt();
    expect($fakes['mumble']->channels)->toHaveKey($voiceChannels['mumble']['server_channel_id']);
    expect($fakes['teamspeak']->channels)->toHaveKey($voiceChannels['teamspeak']['server_channel_id']);
});

it('is idempotent per provider on a re-fired ServerLinkUpdated(Ready)', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = Tournament::factory()->live()->create();
    $match = GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Ready]);
    $link = readyServerLinkForMatch($match);

    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated($link));
    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated($link->fresh()));

    foreach ($fakes as $fake) {
        expect(collect($fake->channels))->toHaveCount(1);
    }
});

it('does nothing when the ServerLink status is not Ready', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = Tournament::factory()->live()->create();
    $match = GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Pending]);

    $link = ServerLink::factory()->create([
        'match_id' => $match->id,
        'status' => ServerLinkStatus::Provisioning,
    ]);

    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated($link));

    foreach ($fakes as $fake) {
        expect(collect($fake->channels))->toBeEmpty();
    }

    expect($match->fresh()->voice_channels)->toBeNull();
});

it('does nothing when the ServerLink has no match_id (tournament-scoped server)', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $link = ServerLink::factory()->create([
        'match_id' => null,
        'tournament_id' => Tournament::factory()->live()->create()->id,
        'status' => ServerLinkStatus::Ready,
    ]);

    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated($link));

    foreach ($fakes as $fake) {
        expect(collect($fake->channels))->toBeEmpty();
    }
});

it('deletes the per-server voice channel per provider on tournament cleanup', function () {
    Bus::fake([CleanupTournamentVoiceJob::class]);

    $fakes = fakeVoice(['mumble', 'teamspeak']);

    $tournament = Tournament::factory()->live()->create();
    $match = GameMatch::factory()->for($tournament)->create(['status' => MatchStatus::Ready]);
    $link = readyServerLinkForMatch($match);

    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated($link));

    $voiceChannels = $match->fresh()->voice_channels;
    $mumbleServerChannelId = $voiceChannels['mumble']['server_channel_id'];
    $teamspeakServerChannelId = $voiceChannels['teamspeak']['server_channel_id'];

    $tournament->update(['status' => TournamentStatus::Finished]);

    event(new TournamentCompleted($tournament));

    app()->call([new CleanupTournamentVoiceJob($tournament->id), 'handle']);

    $fakes['mumble']->assertChannelDeleted($mumbleServerChannelId);
    $fakes['teamspeak']->assertChannelDeleted($teamspeakServerChannelId);

    expect($match->fresh()->voice_channels)->toBeNull();
});
