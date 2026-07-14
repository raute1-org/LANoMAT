<?php

use App\Models\User;
use App\Modules\Teams\Enums\TeamRole;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use Illuminate\Database\QueryException;

it('exposes owner and members relations', function () {
    $team = Team::factory()->create();

    expect($team->owner)->toBeInstanceOf(User::class)
        ->and($team->members)->toHaveCount(0);
});

it('casts member role to the enum', function () {
    $member = TeamMember::factory()->create(['role' => TeamRole::Owner]);

    expect($member->fresh()->role)->toBe(TeamRole::Owner);
});

it('forbids the same user twice in one team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    TeamMember::factory()->for($team)->for($user)->create();

    expect(fn () => TeamMember::factory()->for($team)->for($user)->create())
        ->toThrow(QueryException::class);
});
