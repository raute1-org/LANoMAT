<?php

namespace Database\Factories;

use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PollOption>
 */
class PollOptionFactory extends Factory
{
    protected $model = PollOption::class;

    public function definition(): array
    {
        return [
            'poll_id' => Poll::factory(),
            'label' => fake()->words(2, true),
            'sort' => 0,
        ];
    }
}
