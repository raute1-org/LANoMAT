<?php

namespace Database\Factories;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        // fake()->words() has an ambiguous array|string stub return type
        // regardless of $asText, so two word() calls (unambiguously string)
        // are joined manually instead.
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'icon_path' => null,
            'min_team_size' => 1,
            'max_team_size' => 1,
            'pelican_egg_id' => null,
            // Factories bypass $fillable, so default_server_config can be
            // set directly here (mirrors InfoscreenSceneFactory).
            'default_server_config' => new ServerConfig,
        ];
    }
}
