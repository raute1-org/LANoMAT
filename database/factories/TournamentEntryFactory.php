<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TournamentEntry> */
class TournamentEntryFactory extends Factory
{
    protected $model = TournamentEntry::class;

    public function definition(): array
    {
        return [
            'tournament_id' => Tournament::factory(),
            'team_id' => null,
            'user_id' => User::factory(),
            'display_name' => fake()->unique()->userName(),
            'seed' => null,
            'checked_in_at' => null,
            'roster_snapshot' => null,
            'status' => EntryStatus::Registered,
        ];
    }

    public function solo(): static
    {
        return $this->state(fn (): array => [
            'team_id' => null,
            'user_id' => User::factory(),
        ]);
    }

    public function team(): static
    {
        return $this->state(fn (): array => [
            'team_id' => Team::factory(),
            'user_id' => null,
        ]);
    }

    public function checkedIn(): static
    {
        return $this->state([
            'status' => EntryStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);
    }
}
