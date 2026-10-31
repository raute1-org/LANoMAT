<?php

namespace Database\Factories;

use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebVoiceChannel> */
class WebVoiceChannelFactory extends Factory
{
    protected $model = WebVoiceChannel::class;

    public function definition(): array
    {
        return [
            'kind' => ChannelKind::General,
            'name' => fake()->unique()->words(2, true),
            'description' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    public function match(?int $matchId = null, ?int $entryId = null): static
    {
        return $this->state(fn () => [
            'kind' => ChannelKind::Match,
            'game_match_id' => $matchId ?? fake()->unique()->numberBetween(1000, 99999),
            'tournament_entry_id' => $entryId ?? fake()->unique()->numberBetween(1000, 99999),
        ]);
    }
}
