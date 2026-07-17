<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\GameServers\Actions\DeprovisionServer;
use App\Modules\GameServers\Actions\SetManualJoinInfo;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('lets a helper write manual join info, creating a manual ServerLink with status Ready', function () {
    Event::fake([ServerLinkUpdated::class]);

    $helper = User::factory()->create(['role' => Role::Helper]);
    $match = GameMatch::factory()->create();

    $info = new JoinInfo(address: '203.0.113.5', port: 27015, connectString: 'steam://connect/203.0.113.5:27015');

    $link = (new SetManualJoinInfo)->handle($match, $info, $helper);

    expect($link->manual)->toBeTrue()
        ->and($link->status)->toBe(ServerLinkStatus::Ready)
        ->and($link->join_info->address)->toBe('203.0.113.5')
        ->and($match->fresh()->server_link_id)->toBe($link->id);

    Event::assertDispatched(ServerLinkUpdated::class, fn ($event) => $event->serverLink->id === $link->id);
});

it('upserts the existing ServerLink on a second call rather than creating a duplicate', function () {
    // SetManualJoinInfo dispatches ServerLinkUpdated(Ready), which now also
    // fans out to ProvisionServerVoiceOnReady (issue #13) — fake voice so
    // that reaches an in-memory client rather than a real sidecar.
    fakeMumble();

    $helper = User::factory()->create(['role' => Role::Helper]);
    $match = GameMatch::factory()->create();

    $first = (new SetManualJoinInfo)->handle($match, new JoinInfo(address: '1.2.3.4'), $helper);
    $second = (new SetManualJoinInfo)->handle($match->fresh(), new JoinInfo(address: '5.6.7.8'), $helper);

    expect($second->id)->toBe($first->id)
        ->and($second->fresh()->join_info->address)->toBe('5.6.7.8')
        ->and(ServerLink::query()->count())->toBe(1);
});

it('forbids a participant from setting manual join info', function () {
    $participant = User::factory()->create(['role' => Role::Participant]);
    $match = GameMatch::factory()->create();

    expect(fn () => (new SetManualJoinInfo)->handle($match, new JoinInfo(address: '1.2.3.4'), $participant))
        ->toThrow(AuthorizationException::class);
});

it('lets an orga deprovision a server, deleting it and marking the link Stopped', function () {
    $fake = fakePelican();
    $created = $fake->createServer('egg-1', []);

    $link = ServerLink::factory()->create([
        'pelican_server_id' => $created->id,
        'status' => ServerLinkStatus::Ready,
    ]);

    (new DeprovisionServer)->handle($link);

    $fake->assertServerDeleted($created->id);
    expect($link->fresh()->status)->toBe(ServerLinkStatus::Stopped);
});
