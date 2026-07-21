<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventPhoto>
 */
class EventPhotoFactory extends Factory
{
    protected $model = EventPhoto::class;

    public function definition(): array
    {
        $uuid = $this->faker->uuid();

        return [
            'event_id' => Event::factory(),
            'uploaded_by' => User::factory(),
            'path' => 'event-1/photos/'.$uuid.'.jpg',
            'thumb_path' => 'event-1/photos/'.$uuid.'-thumb.jpg',
            'width' => 1920,
            'height' => 1080,
            'caption' => null,
            'is_highlight' => false,
            'visibility' => PhotoVisibility::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(['visibility' => PhotoVisibility::Approved, 'reviewed_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(['visibility' => PhotoVisibility::Rejected, 'reviewed_at' => now()]);
    }

    public function highlight(): static
    {
        return $this->state(['is_highlight' => true, 'visibility' => PhotoVisibility::Approved]);
    }
}
