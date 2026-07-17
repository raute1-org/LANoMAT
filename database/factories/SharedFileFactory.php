<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedFile>
 */
class SharedFileFactory extends Factory
{
    protected $model = SharedFile::class;

    public function definition(): array
    {
        $originalName = $this->faker->word().'.zip';

        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'disk' => 'local',
            'path' => 'event-'.$this->faker->numberBetween(1, 1000).'/'.$this->faker->uuid().'-'.$originalName,
            'original_name' => $originalName,
            'size_bytes' => $this->faker->numberBetween(1024, 1024 * 1024),
            'mime' => 'application/zip',
            'visibility' => FileVisibility::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state([
            'visibility' => FileVisibility::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'visibility' => FileVisibility::Rejected,
            'reviewed_at' => now(),
        ]);
    }
}
