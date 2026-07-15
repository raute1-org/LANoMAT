<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleItem>
 */
class ScheduleItemFactory extends Factory
{
    protected $model = ScheduleItem::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'type' => ScheduleItemType::Custom,
            'title' => fake()->sentence(3),
            'starts_at' => now()->addHour(),
        ];
    }

    public function tournament(): static
    {
        return $this->state(['type' => ScheduleItemType::Tournament]);
    }

    public function catering(): static
    {
        return $this->state(['type' => ScheduleItemType::Catering]);
    }

    public function custom(): static
    {
        return $this->state(['type' => ScheduleItemType::Custom]);
    }
}
