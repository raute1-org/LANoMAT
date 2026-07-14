<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tournament> */
class TournamentFactory extends Factory
{
    protected $model = Tournament::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'game_id' => null,
            'name' => fake()->unique()->words(2, true),
            'format' => TournamentFormat::SingleElimination,
            'status' => TournamentStatus::Draft,
            'team_size' => 1,
            'max_entries' => null,
            'rules' => null,
            'starts_at' => now()->addDay(),
            'checkin_opens_at' => null,
            'checkin_closes_at' => null,
            'settings' => [],
        ];
    }

    public function enrollment(): static
    {
        return $this->state(['status' => TournamentStatus::Enrollment]);
    }

    public function checkIn(): static
    {
        return $this->state(['status' => TournamentStatus::CheckIn]);
    }

    public function live(): static
    {
        return $this->state(['status' => TournamentStatus::Live]);
    }

    public function singleElim(): static
    {
        return $this->state(['format' => TournamentFormat::SingleElimination]);
    }

    public function doubleElim(): static
    {
        return $this->state(['format' => TournamentFormat::DoubleElimination]);
    }
}
