<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Seating\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Seat> */
class SeatFactory extends Factory
{
    protected $model = Seat::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'label' => strtoupper(fake()->unique()->bothify('?#')),
            'pos_x' => fake()->numberBetween(0, 10),
            'pos_y' => fake()->numberBetween(0, 10),
            'meta' => [],
        ];
    }
}
