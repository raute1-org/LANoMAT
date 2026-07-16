<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Models\TombolaPrize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TombolaPrize>
 */
class TombolaPrizeFactory extends Factory
{
    protected $model = TombolaPrize::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'title' => fake()->words(3, true),
            'sort' => 0,
        ];
    }

    public function sort(int $n): static
    {
        return $this->state(['sort' => $n]);
    }
}
