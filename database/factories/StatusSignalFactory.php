<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Enums\StatusLevel;
use App\Modules\Infoscreen\Models\StatusSignal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusSignal>
 */
class StatusSignalFactory extends Factory
{
    protected $model = StatusSignal::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'component' => 'internet',
            'level' => StatusLevel::Ok,
            'message' => null,
        ];
    }

    public function component(string $component): static
    {
        return $this->state(['component' => $component]);
    }

    public function level(StatusLevel $level): static
    {
        return $this->state(['level' => $level]);
    }

    public function down(): static
    {
        return $this->state(['level' => StatusLevel::Down]);
    }
}
