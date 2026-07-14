<?php

namespace Database\Factories;

use App\Modules\Tournaments\Domain\Bracket;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameMatch> */
class GameMatchFactory extends Factory
{
    protected $model = GameMatch::class;

    public function definition(): array
    {
        return [
            'tournament_id' => Tournament::factory(),
            'round' => 1,
            'bracket' => Bracket::Winners->value,
            'position' => 0,
            'entry1_id' => null,
            'entry2_id' => null,
            'status' => MatchStatus::Pending,
            'scheduled_at' => null,
            'next_match_id' => null,
            'next_slot' => null,
            'loser_match_id' => null,
            'loser_slot' => null,
            'discord_channels' => null,
            'voice_channels' => null,
        ];
    }
}
