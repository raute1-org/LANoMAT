<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Models\ScheduleItemFavorite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleItemFavorite>
 */
class ScheduleItemFavoriteFactory extends Factory
{
    protected $model = ScheduleItemFavorite::class;

    public function definition(): array
    {
        return [
            'schedule_item_id' => ScheduleItem::factory(),
            'user_id' => User::factory(),
            'reminded_at' => null,
        ];
    }
}
