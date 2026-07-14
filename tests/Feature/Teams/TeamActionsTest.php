<?php

use App\Models\User;
use App\Modules\Teams\Actions\CreateTeam;
use App\Modules\Teams\Actions\LeaveTeam;
use App\Modules\Teams\Actions\RequestToJoin;
use App\Modules\Teams\Actions\RespondToJoinRequest;
use App\Modules\Teams\Actions\TransferOwnership;
use App\Modules\Teams\Enums\JoinRequestStatus;
use App\Modules\Teams\Enums\TeamRole;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a team with the owner as a member', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha', 'ALP');

    expect($team->owner_id)->toBe($owner->id)
        ->and($team->members()->where('user_id', $owner->id)->first()->role)->toBe(TeamRole::Owner);
});

it('rejects a duplicate join request', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    app(RequestToJoin::class)->handle($user, $team);

    expect(fn () => app(RequestToJoin::class)->handle($user, $team))->toThrow(TeamException::class);
});

it('rejects a join request from an existing member', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha', 'ALP');

    expect(fn () => app(RequestToJoin::class)->handle($owner, $team))->toThrow(TeamException::class);
});

it('adds a member when a join request is accepted', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $req = app(RequestToJoin::class)->handle($user, $team);

    app(RespondToJoinRequest::class)->handle($req, accept: true);

    expect($req->fresh()->status)->toBe(JoinRequestStatus::Accepted)
        ->and($team->members()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('declines a join request without adding a member', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $req = app(RequestToJoin::class)->handle($user, $team);

    app(RespondToJoinRequest::class)->handle($req, accept: false);

    expect($req->fresh()->status)->toBe(JoinRequestStatus::Declined)
        ->and($team->members()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not duplicate the member row when accepting a request for an existing member', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $req = TeamJoinRequest::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
    ]);
    // Simulate the user already being a member (e.g. added by another path).
    $team->members()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    app(RespondToJoinRequest::class)->handle($req, accept: true);

    expect($team->members()->where('user_id', $user->id)->count())->toBe(1);
});

it('forbids the owner from leaving without transfer', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha', 'ALP');

    expect(fn () => app(LeaveTeam::class)->handle($owner, $team))->toThrow(TeamException::class);
});

it('removes the member row when a non-owner member leaves', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha', 'ALP');
    $member = User::factory()->create();
    app(RespondToJoinRequest::class)->handle(app(RequestToJoin::class)->handle($member, $team), accept: true);

    app(LeaveTeam::class)->handle($member, $team);

    expect($team->members()->where('user_id', $member->id)->exists())->toBeFalse();
});

it('rejects transferring ownership to a non-member', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha', 'ALP');
    $stranger = User::factory()->create();

    expect(fn () => app(TransferOwnership::class)->handle($team, $stranger))->toThrow(TeamException::class);
});

it('handles decline → reapply → decline again without error', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $first = app(RequestToJoin::class)->handle($user, $team);
    app(RespondToJoinRequest::class)->handle($first, accept: false);

    $second = app(RequestToJoin::class)->handle($user, $team);
    app(RespondToJoinRequest::class)->handle($second, accept: false);

    expect($second->fresh()->status)->toBe(JoinRequestStatus::Declined)
        ->and(TeamJoinRequest::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('status', JoinRequestStatus::Declined->value)
            ->count())->toBe(1);
});

it('handles accept → leave → re-request → accept without a unique-constraint violation', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha', 'ALP');
    $user = User::factory()->create();

    $first = app(RequestToJoin::class)->handle($user, $team);
    app(RespondToJoinRequest::class)->handle($first, accept: true);
    expect($team->members()->where('user_id', $user->id)->exists())->toBeTrue();

    app(LeaveTeam::class)->handle($user, $team);
    expect($team->members()->where('user_id', $user->id)->exists())->toBeFalse();

    // Re-requesting after leaving must not collide with the now-terminal
    // Accepted row from the first request (unique on team_id/user_id/status
    // would otherwise permanently block this once the earlier row is
    // Accepted, not just Declined).
    $second = app(RequestToJoin::class)->handle($user, $team);
    app(RespondToJoinRequest::class)->handle($second, accept: true);

    expect($second->fresh()->status)->toBe(JoinRequestStatus::Accepted)
        ->and($team->members()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('transfers ownership to a member', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Alpha', 'ALP');
    $member = User::factory()->create();
    app(RespondToJoinRequest::class)->handle(app(RequestToJoin::class)->handle($member, $team), accept: true);

    app(TransferOwnership::class)->handle($team, $member);

    expect($team->fresh()->owner_id)->toBe($member->id)
        ->and($team->members()->where('user_id', $member->id)->first()->role)->toBe(TeamRole::Owner)
        ->and($team->members()->where('user_id', $owner->id)->first()->role)->toBe(TeamRole::Member);
});
