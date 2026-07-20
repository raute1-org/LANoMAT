<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use App\Modules\Jukebox\Models\JukeboxItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JukeboxItem> */
class JukeboxItemFactory extends Factory
{
    protected $model = JukeboxItem::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'added_by' => User::factory(),
            'uri' => 'ma://track/'.$this->faker->uuid(),
            'title' => $this->faker->words(3, true),
            'status' => QueueItemStatus::Queued,
        ];
    }
}
