<?php

namespace Database\Seeders;

use App\Modules\Games\Models\Game;
use Illuminate\Database\Seeder;

/**
 * Seeds a small, realistic games catalog.
 *
 * `pelican_egg_id` is deliberately left null here: real egg IDs come from
 * the deferred T13 Pelican infra spike, not this task. `default_server_config`
 * is left empty — typed presets are added in T9.
 */
class GamesSeeder extends Seeder
{
    public function run(): void
    {
        $games = [
            ['name' => 'Counter-Strike 2', 'slug' => 'counter-strike-2', 'min_team_size' => 5, 'max_team_size' => 5],
            ['name' => 'Minecraft', 'slug' => 'minecraft', 'min_team_size' => 1, 'max_team_size' => 1],
            ['name' => 'Rocket League', 'slug' => 'rocket-league', 'min_team_size' => 3, 'max_team_size' => 3],
            ['name' => 'League of Legends', 'slug' => 'league-of-legends', 'min_team_size' => 5, 'max_team_size' => 5],
        ];

        foreach ($games as $game) {
            Game::query()->firstOrCreate(
                ['slug' => $game['slug']],
                [
                    'name' => $game['name'],
                    'min_team_size' => $game['min_team_size'],
                    'max_team_size' => $game['max_team_size'],
                    'pelican_egg_id' => null,
                ],
            );
        }
    }
}
