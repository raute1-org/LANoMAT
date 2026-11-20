<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GameRecipe>
 */
class GameRecipeFactory extends Factory
{
    protected $model = GameRecipe::class;

    public function definition(): array
    {
        // fake()->words(2, true) has an ambiguous array|string stub return
        // type; two word() calls are unambiguously string (mirrors GameFactory).
        $title = fake()->unique()->word().' '.fake()->word();
        $slug = Str::slug($title);

        return [
            'slug' => $slug,
            'title' => Str::title($title),
            'aliases' => null,
            'version' => null,
            'status' => RecipeStatus::Draft,
            'source' => 'bundled',
            'source_ref' => null,
            'platforms' => ['windows'],
            'summary' => fake()->sentence(),
            'description' => null,
            'homepage' => null,
            'license' => null,
            'cover_image_path' => null,
            'skill_markdown' => "# {$slug}\n\nInstall the game from the LAN.\n",
            'requirements' => [],
            'steps' => [],
            'metadata' => [],
            'contributor_user_id' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status' => RecipeStatus::Draft,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    /** A published recipe, as it looks after an orga released it through the panel. */
    public function published(): static
    {
        return $this->state([
            'status' => RecipeStatus::Published,
            'reviewed_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state([
            'status' => RecipeStatus::Archived,
            'reviewed_at' => now(),
        ]);
    }

    /** A recipe a participant contributed through the MCP tool; drafts until an orga publishes it. */
    public function contributedBy(User $user): static
    {
        return $this->state([
            'status' => RecipeStatus::Draft,
            'source' => 'contribution',
            'contributor_user_id' => $user->id,
        ]);
    }

    /** @param list<array<string, mixed>> $requirements */
    public function withRequirements(array $requirements): static
    {
        return $this->state(['requirements' => $requirements]);
    }
}
