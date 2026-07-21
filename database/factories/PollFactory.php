<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Voting\Enums\PollKind;
use App\Modules\Voting\Enums\PollStatus;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Models\PollOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Poll>
 */
class PollFactory extends Factory
{
    protected $model = Poll::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'question' => fake()->sentence().'?',
            'status' => PollStatus::Draft,
            'kind' => PollKind::Standard,
            'closes_at' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => PollStatus::Open]);
    }

    public function closed(): static
    {
        return $this->state(['status' => PollStatus::Closed]);
    }

    public function mvp(): static
    {
        return $this->state(['kind' => PollKind::Mvp]);
    }

    /**
     * Attach $count PollOption rows (in ascending sort order) once the
     * poll is created.
     */
    public function withOptions(int $count = 2): static
    {
        return $this->afterCreating(function (Poll $poll) use ($count): void {
            for ($i = 0; $i < $count; $i++) {
                $poll->options()->save(
                    PollOption::factory()->make(['sort' => $i])
                );
            }
        });
    }
}
