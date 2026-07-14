<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MatchReport> */
class MatchReportFactory extends Factory
{
    protected $model = MatchReport::class;

    public function definition(): array
    {
        return [
            'match_id' => GameMatch::factory(),
            'reported_by' => User::factory(),
            'score1' => fake()->numberBetween(0, 3),
            'score2' => fake()->numberBetween(0, 3),
            'status' => ReportStatus::Pending,
        ];
    }
}
