<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Models\UserBlock;
use App\Modules\Presence\Domain\GameLocation;
use App\Modules\Presence\Domain\PersonLocation;
use App\Modules\Presence\Jobs\RefreshLocationSnapshotJob;
use App\Modules\Presence\Support\LocatedGames;
use App\Modules\Presence\Support\LocationSnapshot;
use App\Modules\Presence\Support\LocationSnapshotStore;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PresenceHelpers.php';

uses(RefreshDatabase::class);

/** @param array<string, string|null> $games user name => game name */
function locatedSnapshot(array $users, array $games): void
{
    $people = [];

    foreach ($games as $name => $game) {
        $id = $users[$name]->id;
        $people[$id] = new PersonLocation($id, [], new GameLocation(GameLocation::SOURCE_STEAM, $game, null, null, null, null, now()->toIso8601String()));
    }

    app(LocationSnapshotStore::class)->put(new LocationSnapshot(now()->toIso8601String(), $people));
}

beforeEach(function () {
    $this->world = presenceWorld();
    $this->event = $this->world['event'];
    $this->users = $this->world['users'];
});

it('counts located participants per game name, the viewer included', function () {
    locatedSnapshot($this->users, ['Max' => 'Counter-Strike 2', 'Ada' => 'Counter-Strike 2', 'Bea' => 'Quake Live', 'Cem' => null]);

    expect(app(LocatedGames::class)->countsFor($this->event, $this->users['Max']))
        ->toBe(['Counter-Strike 2' => 2, 'Quake Live' => 1]);
});

it('does not count a user who switched share_location off, but counts the viewer', function () {
    locatedSnapshot($this->users, ['Max' => 'Quake Live', 'Ada' => 'Quake Live']);
    $this->users['Ada']->forceFill(['share_location' => false])->save();
    $this->users['Max']->forceFill(['share_location' => false])->save();

    expect(app(LocatedGames::class)->countsFor($this->event, $this->users['Max']->refresh()))->toBe(['Quake Live' => 1]);
});

it('does not count people blocked either way with the viewer', function () {
    locatedSnapshot($this->users, ['Max' => 'Quake Live', 'Ada' => 'Quake Live', 'Bea' => 'Quake Live', 'Cem' => 'Quake Live']);
    UserBlock::factory()->create(['blocker_id' => $this->users['Max']->id, 'blocked_id' => $this->users['Ada']->id]);
    UserBlock::factory()->create(['blocker_id' => $this->users['Bea']->id, 'blocked_id' => $this->users['Max']->id]);

    expect(app(LocatedGames::class)->countsFor($this->event, $this->users['Max']))->toBe(['Quake Live' => 2]);
});

it('does not count cancelled registrations or non-participants', function () {
    locatedSnapshot($this->users, ['Ada' => 'Quake Live', 'Bea' => 'Quake Live']);
    EventRegistration::query()->where('user_id', $this->users['Ada']->id)->update(['status' => RegistrationStatus::Cancelled->value]);
    $outsider = User::factory()->create();
    $people = app(LocationSnapshotStore::class)->get()->people;
    $people[$outsider->id] = new PersonLocation($outsider->id, [], new GameLocation('steam', 'Quake Live', null, null, null, null, now()->toIso8601String()));
    app(LocationSnapshotStore::class)->put(new LocationSnapshot(now()->toIso8601String(), $people));

    expect(app(LocatedGames::class)->countsFor($this->event, $this->users['Max']))->toBe(['Quake Live' => 1]);
});

it('builds the snapshot on a cold cache and returns nothing when it stays empty', function () {
    app(LocationSnapshotStore::class)->forget();

    expect(app(LocatedGames::class)->countsFor($this->event, $this->users['Max']))->toBe([])
        ->and(app(LocationSnapshotStore::class)->get())->not->toBeNull();
});

it('queues the snapshot refresh only once while one is pending', function () {
    Queue::fake();

    RefreshLocationSnapshotJob::dispatch();
    RefreshLocationSnapshotJob::dispatch();

    expect(new RefreshLocationSnapshotJob)->toBeInstanceOf(ShouldBeUnique::class);
    Queue::assertPushed(RefreshLocationSnapshotJob::class, 1);
});
