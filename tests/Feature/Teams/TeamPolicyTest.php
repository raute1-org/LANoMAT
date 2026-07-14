<?php

use App\Models\User;
use App\Modules\Teams\Models\Team;

it('allows only owner or orga to update a team', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);

    expect($owner->can('update', $team))->toBeTrue()
        ->and(User::factory()->orga()->create()->can('update', $team))->toBeTrue()
        ->and(User::factory()->create()->can('update', $team))->toBeFalse();
});
