<?php

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows enroll only while the tournament is in enrollment status', function () {
    $user = User::factory()->create();
    $enrollmentTournament = Tournament::factory()->enrollment()->create();
    $draftTournament = Tournament::factory()->create(); // draft

    expect($user->can('enroll', $enrollmentTournament))->toBeTrue()
        ->and($user->can('enroll', $draftTournament))->toBeFalse();
});

it('allows check-in for the entry owner (solo)', function () {
    $owner = User::factory()->create();
    $entry = TournamentEntry::factory()->solo()->create(['user_id' => $owner->id]);

    expect($owner->can('checkIn', $entry))->toBeTrue();
});

it('allows check-in for the team owner of the entry team', function () {
    $teamOwner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $teamOwner->id]);
    $entry = TournamentEntry::factory()->team()->create(['team_id' => $team->id]);

    expect($teamOwner->can('checkIn', $entry))->toBeTrue();
});

it('denies check-in for an unrelated user', function () {
    $stranger = User::factory()->create();

    $soloEntry = TournamentEntry::factory()->solo()->create();
    expect($stranger->can('checkIn', $soloEntry))->toBeFalse();

    $team = Team::factory()->create();
    $teamEntry = TournamentEntry::factory()->team()->create(['team_id' => $team->id]);
    expect($stranger->can('checkIn', $teamEntry))->toBeFalse();
});

it('denies check-in for a mere team member who is not the team owner', function () {
    $teamOwner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $teamOwner->id]);
    $member = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $member->id]);
    $entry = TournamentEntry::factory()->team()->create(['team_id' => $team->id]);

    expect($member->can('checkIn', $entry))->toBeFalse();
});

it('allows check-in via the orga override regardless of entry ownership', function () {
    $orga = User::factory()->orga()->create();
    $soloEntry = TournamentEntry::factory()->solo()->create();

    expect($orga->can('checkIn', $soloEntry))->toBeTrue();
});

it('allows manage only for orga, not for ordinary participants', function () {
    $tournament = Tournament::factory()->create();

    expect(User::factory()->orga()->create()->can('manage', $tournament))->toBeTrue()
        ->and(User::factory()->create()->can('manage', $tournament))->toBeFalse();
});

it('lets the admin Gate::before short-circuit checkIn even for an unrelated user', function () {
    $admin = User::factory()->admin()->create();
    $soloEntry = TournamentEntry::factory()->solo()->create();

    expect($admin->can('checkIn', $soloEntry))->toBeTrue();
});
