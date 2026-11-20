<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameRecipeFile>
 */
class GameRecipeFileFactory extends Factory
{
    protected $model = GameRecipeFile::class;

    public function definition(): array
    {
        return [
            'game_recipe_id' => GameRecipe::factory(),
            'role' => RecipeFileRole::Content,
            'label' => fake()->word().' '.fake()->word(),
            'match_patterns' => ['*'.fake()->lexify('????').'.zip'],
            'size_bytes' => null,
            'sha256' => null,
            'url' => null,
            'required' => false,
            'sort' => 0,
            'metadata' => null,
            'shared_file_id' => null,
        ];
    }

    /** A required disc image, as an ordinary game recipe needs it. */
    public function iso(): static
    {
        return $this->state([
            'role' => RecipeFileRole::Iso,
            'label' => 'Disc',
            'match_patterns' => ['*.iso'],
            'required' => true,
        ]);
    }

    /** A selectable avatar/content file (see GameRecipe::avatarFiles()). */
    public function avatar(): static
    {
        return $this->state([
            'role' => RecipeFileRole::Avatar,
            'label' => 'Avatar',
            'match_patterns' => ['*.vrm'],
            'required' => false,
        ]);
    }
}
