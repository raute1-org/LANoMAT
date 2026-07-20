<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Friends\Models\UserBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserBlock> */
class UserBlockFactory extends Factory
{
    protected $model = UserBlock::class;

    public function definition(): array
    {
        return [
            'blocker_id' => User::factory(),
            'blocked_id' => User::factory(),
        ];
    }
}
