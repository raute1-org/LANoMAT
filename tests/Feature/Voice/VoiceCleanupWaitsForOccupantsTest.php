<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Voice\Jobs\CleanupEventServerVoiceJob;
use App\Modules\Voice\Jobs\CleanupTournamentVoiceJob;
use App\Modules\Voice\Jobs\ProvisionEventServerVoiceJob;
use App\Modules\Voice\Models\VoiceServerChannel;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * A sync queue ignores delays, so the job never re-queues itself there. Tests of the waiting
 * behaviour switch to a queue that honours `delay` once the channels exist (provisioning itself
 * relies on the sync queue running the listeners inline).
 */
function useDelayingQueue(): void
{
    config(['queue.default' => 'redis']);
}

function startedTournamentWithVoice(): Tournament
{
    $tournament = Tournament::factory()->live()->create(['name' => 'Valorant Cup', 'team_size' => 5]);
    TournamentEntry::factory()->team()->for($tournament)->create(['display_name' => 'Team Alpha']);
    TournamentEntry::factory()->team()->for($tournament)->create(['display_name' => 'Team Bravo']);
    event(new TournamentStarted($tournament));

    return $tournament->fresh();
}

it('keeps a tournament voice tree while someone is in a team channel and checks again later', function () {
    $fakes = fakeVoice(['mumble']);
    $tournament = startedTournamentWithVoice();
    $teamChannel = $tournament->settings['voice']['mumble']['team_channel_ids'][0];
    $fakes['mumble']->setOccupants($teamChannel, 1);
    Bus::fake([CleanupTournamentVoiceJob::class]);
    useDelayingQueue();

    (new CleanupTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class));

    expect($fakes['mumble']->channels)->toHaveKey($teamChannel);
    expect($tournament->fresh()->settings['voice']['mumble']['team_channel_ids'])->toContain($teamChannel);
    Bus::assertDispatched(
        CleanupTournamentVoiceJob::class,
        fn (CleanupTournamentVoiceJob $job) => $job->tournamentId === $tournament->id && $job->delay !== null,
    );
});

it('removes the tree once the last person has left', function () {
    $fakes = fakeVoice(['mumble']);
    $tournament = startedTournamentWithVoice();
    $teamChannel = $tournament->settings['voice']['mumble']['team_channel_ids'][0];
    $fakes['mumble']->setOccupants($teamChannel, 1);
    Bus::fake([CleanupTournamentVoiceJob::class]);
    useDelayingQueue();
    (new CleanupTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class));

    $fakes['mumble']->setOccupants($teamChannel, 0);
    (new CleanupTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class));

    expect($fakes['mumble']->channels)->not->toHaveKey($teamChannel);
    expect($tournament->fresh()->settings)->not->toHaveKey('voice');
});

it('treats a person in a sub-channel as being in the tournament channel', function () {
    $fakes = fakeVoice(['mumble']);
    $tournament = startedTournamentWithVoice();
    $root = $tournament->settings['voice']['mumble']['tournament_channel_id'];
    $lounge = $fakes['mumble']->createChannel('Lounge', $root);
    $fakes['mumble']->setOccupants($lounge->id, 1);
    Bus::fake([CleanupTournamentVoiceJob::class]);
    useDelayingQueue();

    (new CleanupTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class));

    expect($fakes['mumble']->channels)->toHaveKey($root);
    Bus::assertDispatched(CleanupTournamentVoiceJob::class);
});

it('cleans up a provider that is empty while another one still has people in it', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);
    $tournament = startedTournamentWithVoice();
    $mumbleRoot = $tournament->settings['voice']['mumble']['tournament_channel_id'];
    $teamspeakRoot = $tournament->settings['voice']['teamspeak']['tournament_channel_id'];
    $fakes['mumble']->setOccupants($tournament->settings['voice']['mumble']['team_channel_ids'][0], 2);
    Bus::fake([CleanupTournamentVoiceJob::class]);
    useDelayingQueue();

    (new CleanupTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class));

    expect($fakes['teamspeak']->channels)->not->toHaveKey($teamspeakRoot);
    expect($fakes['mumble']->channels)->toHaveKey($mumbleRoot);
    $settings = $tournament->fresh()->settings;
    expect($settings['voice'])->toHaveKey('mumble')->not->toHaveKey('teamspeak');
    Bus::assertDispatched(CleanupTournamentVoiceJob::class);
});

it('does not queue another check when nobody is in the channels', function () {
    $fakes = fakeVoice(['mumble']);
    $tournament = startedTournamentWithVoice();
    Bus::fake([CleanupTournamentVoiceJob::class]);
    useDelayingQueue();

    (new CleanupTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class));

    Bus::assertNotDispatched(CleanupTournamentVoiceJob::class);
    expect(collect($fakes['mumble']->channels))->toBeEmpty();
});

it('does not re-queue itself on a sync queue, where a delay would loop forever', function () {
    $fakes = fakeVoice(['mumble']);
    $tournament = startedTournamentWithVoice();
    $fakes['mumble']->setOccupants($tournament->settings['voice']['mumble']['team_channel_ids'][0], 1);
    Bus::fake([CleanupTournamentVoiceJob::class]);
    config(['queue.default' => 'sync']);

    (new CleanupTournamentVoiceJob($tournament->id))->handle(app(VoiceProviders::class));

    Bus::assertNotDispatched(CleanupTournamentVoiceJob::class);
});

it('keeps an event server channel while someone is in it, then deletes it', function () {
    $fakes = fakeVoice(['mumble']);
    $link = ServerLink::factory()->create([
        'event_id' => Event::factory()->create()->id,
        'match_id' => null,
        'title' => 'QA-TTT-Test',
        'status' => ServerLinkStatus::Stopped,
    ]);
    $providers = app(VoiceProviders::class);
    (new ProvisionEventServerVoiceJob($link->id, 'QA-TTT-Test'))->handle($providers);
    $channelId = (int) VoiceServerChannel::query()->where('server_link_id', $link->id)->value('channel_id');
    $fakes['mumble']->setOccupants($channelId, 1);
    Bus::fake([CleanupEventServerVoiceJob::class]);
    useDelayingQueue();

    (new CleanupEventServerVoiceJob($link->id))->handle($providers);

    expect($fakes['mumble']->channels)->toHaveKey($channelId);
    expect(VoiceServerChannel::query()->where('server_link_id', $link->id)->count())->toBe(1);
    Bus::assertDispatched(CleanupEventServerVoiceJob::class, fn ($job) => $job->delay !== null);

    $fakes['mumble']->setOccupants($channelId, 0);
    (new CleanupEventServerVoiceJob($link->id))->handle($providers);

    $fakes['mumble']->assertChannelDeleted($channelId);
    expect(VoiceServerChannel::query()->where('server_link_id', $link->id)->count())->toBe(0);
});
