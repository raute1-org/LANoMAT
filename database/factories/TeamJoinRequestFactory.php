<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Teams\Enums\JoinRequestStatus;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamJoinRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeamJoinRequest> */
class TeamJoinRequestFactory extends Factory
{
    protected $model = TeamJoinRequest::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'status' => JoinRequestStatus::Pending,
            'message' => null,
        ];
    }
}
