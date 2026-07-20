<?php

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\EntryRoster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

function userQueryLog(): Collection
{
    return collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], '"users"'));
}

it('userIdsFor extracts the solo user id without a query', function () {
    $user = User::factory()->create();
    $solo = TournamentEntry::factory()->create(['user_id' => $user->id, 'roster_snapshot' => null]);

    DB::enableQueryLog();
    $ids = EntryRoster::userIdsFor($solo);

    expect($ids)->toBe([$user->id])
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('userIdsFor extracts roster_snapshot user ids without a query', function () {
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $team = TournamentEntry::factory()->create([
        'user_id' => null,
        'team_id' => Team::factory(),
        'roster_snapshot' => [
            ['user_id' => $memberA->id],
            ['user_id' => $memberB->id],
        ],
    ]);

    DB::enableQueryLog();
    $ids = EntryRoster::userIdsFor($team);

    expect($ids)->toBe([$memberA->id, $memberB->id])
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('userIdsFor returns an empty array when neither user_id nor roster_snapshot is set', function () {
    $entry = TournamentEntry::factory()->make(['user_id' => null, 'roster_snapshot' => null]);

    expect(EntryRoster::userIdsFor($entry))->toBe([]);
});

it('resolves users for many entries in a single query', function () {
    $entries = TournamentEntry::factory()->count(4)->create();

    DB::enableQueryLog();
    $users = EntryRoster::usersForEntries($entries);

    expect(userQueryLog())->toHaveCount(1)
        ->and($users)->toHaveCount(4);
});

it('usersForEntries dedupes shared users and keys the result by user id', function () {
    $sharedUser = User::factory()->create();
    $entryA = TournamentEntry::factory()->create(['user_id' => $sharedUser->id, 'roster_snapshot' => null]);
    $entryB = TournamentEntry::factory()->create(['user_id' => $sharedUser->id, 'roster_snapshot' => null]);

    $users = EntryRoster::usersForEntries(collect([$entryA, $entryB]));

    expect($users)->toHaveCount(1)
        ->and($users->keys()->all())->toBe([$sharedUser->id])
        ->and($users->get($sharedUser->id)->is($sharedUser))->toBeTrue();
});

it('usersForMatch resolves the union of both entries in a single query', function () {
    $tournament = Tournament::factory()->create();
    $entry1 = TournamentEntry::factory()->for($tournament)->create();
    $entry2 = TournamentEntry::factory()->for($tournament)->create();
    $match = GameMatch::factory()->for($tournament)->create([
        'entry1_id' => $entry1->id,
        'entry2_id' => $entry2->id,
    ]);

    DB::enableQueryLog();
    $users = EntryRoster::usersForMatch($match->fresh(['entry1', 'entry2']));

    expect(userQueryLog())->toHaveCount(1)
        ->and($users)->toHaveCount(2)
        ->and($users->pluck('id')->sort()->values()->all())
        ->toBe(collect([$entry1->user_id, $entry2->user_id])->sort()->values()->all());
});

it('usersForTournament resolves the distinct union of every entry in a single query', function () {
    $tournament = Tournament::factory()->create();
    $sharedUser = User::factory()->create();

    // Two solo entries, one of them sharing a user with another entry's team roster.
    TournamentEntry::factory()->for($tournament)->create(['user_id' => $sharedUser->id, 'roster_snapshot' => null]);
    $team = Team::factory()->create();
    TeamMember::factory()->for($team)->for($sharedUser, 'user')->create();
    TournamentEntry::factory()->for($tournament)->create([
        'user_id' => null,
        'team_id' => $team->id,
        'roster_snapshot' => [['user_id' => $sharedUser->id]],
    ]);

    DB::enableQueryLog();
    $users = EntryRoster::usersForTournament($tournament->id);

    // 1 query to fetch the tournament's entries + 1 query to batch-resolve users.
    expect(userQueryLog())->toHaveCount(1)
        ->and($users)->toHaveCount(1)
        ->and($users->first()->is($sharedUser))->toBeTrue();
});
