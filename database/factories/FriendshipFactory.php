<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Models\Friendship;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Friendship> */
class FriendshipFactory extends Factory
{
    protected $model = Friendship::class;

    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'addressee_id' => User::factory(),
            'status' => FriendshipStatus::Pending,
        ];
    }
}
