<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LfgPost>
 */
class LfgPostFactory extends Factory
{
    protected $model = LfgPost::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'game' => $this->faker->randomElement(['Valorant', 'League of Legends', 'CS2', 'Overwatch 2']),
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->optional()->paragraph(),
            'slots_needed' => $this->faker->numberBetween(1, 4),
            'expires_at' => now()->addHours(3),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subHour()]);
    }
}
