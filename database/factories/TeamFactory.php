<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'tag' => strtoupper(Str::random(3)),
            'logo_path' => null,
            'owner_id' => User::factory(),
        ];
    }
}
