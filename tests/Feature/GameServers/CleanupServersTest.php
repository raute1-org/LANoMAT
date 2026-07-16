<?php

use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Jobs\CleanupServerJob;
use App\Modules\GameServers\Listeners\CleanupServersOnCompleted;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('dispatches a delayed CleanupServerJob for each match server link and any tournament-level link', function () {
    Bus::fake();

    $tournament = Tournament::factory()->create();

    $link1 = ServerLink::factory()->create();
    $link2 = ServerLink::factory()->create();
    $tournamentLink = ServerLink::factory()->create(['tournament_id' => $tournament->id]);

    GameMatch::factory()->for($tournament)->create(['server_link_id' => $link1->id]);
    GameMatch::factory()->for($tournament)->create(['server_link_id' => $link2->id]);
    GameMatch::factory()->for($tournament)->create(['server_link_id' => null]);

    (new CleanupServersOnCompleted)->handle(new TournamentCompleted($tournament));

    Bus::assertDispatched(CleanupServerJob::class, fn ($job) => $job->serverLinkId === $link1->id);
    Bus::assertDispatched(CleanupServerJob::class, fn ($job) => $job->serverLinkId === $link2->id);
    Bus::assertDispatched(CleanupServerJob::class, fn ($job) => $job->serverLinkId === $tournamentLink->id);
});

it('deletes the pelican server and marks the link Stopped', function () {
    $fake = fakePelican();

    $created = $fake->createServer('egg-1', []);
    $link = ServerLink::factory()->create([
        'pelican_server_id' => $created->id,
        'status' => ServerLinkStatus::Ready,
    ]);

    (new CleanupServerJob($link->id))->handle($fake);

    $fake->assertServerDeleted($created->id);

    expect($link->fresh()->status)->toBe(ServerLinkStatus::Stopped);
});

it('is idempotent: re-running CleanupServerJob after Stopped is a no-op', function () {
    $fake = fakePelican();

    $created = $fake->createServer('egg-1', []);
    $link = ServerLink::factory()->create([
        'pelican_server_id' => $created->id,
        'status' => ServerLinkStatus::Ready,
    ]);

    (new CleanupServerJob($link->id))->handle($fake);
    expect($fake->deleted)->toHaveCount(1);

    (new CleanupServerJob($link->id))->handle($fake);
    expect($fake->deleted)->toHaveCount(1);
    expect($link->fresh()->status)->toBe(ServerLinkStatus::Stopped);
});

it('is a no-op when the link has no pelican_server_id (manual link)', function () {
    $fake = fakePelican();

    $link = ServerLink::factory()->create([
        'pelican_server_id' => null,
        'manual' => true,
        'status' => ServerLinkStatus::Ready,
    ]);

    (new CleanupServerJob($link->id))->handle($fake);

    expect($fake->deleted)->toHaveCount(0);
    expect($link->fresh()->status)->toBe(ServerLinkStatus::Stopped);
});
