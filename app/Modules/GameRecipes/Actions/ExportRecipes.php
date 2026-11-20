<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Support\Facades\File;

/**
 * Writes the published recipes as portable Agent Skill directories
 * (`<slug>/SKILL.md` + `recipe.json`). Used for an offline installer bundle
 * and as a CI artefact, so a Pi install can be seeded without a running
 * server.
 */
final class ExportRecipes
{
    /** @return array{written: int, path: string} */
    public function handle(string $destination, bool $publishedOnly = true): array
    {
        File::ensureDirectoryExists($destination, 0755);

        $recipes = GameRecipe::query()
            ->when($publishedOnly, fn ($query) => $query->published())
            ->orderBy('slug')
            ->get();

        $written = 0;

        foreach ($recipes as $recipe) {
            $dir = rtrim($destination, '/').'/'.$recipe->slug;
            File::ensureDirectoryExists($dir, 0755);

            File::put($dir.'/SKILL.md', (string) $recipe->skill_markdown);
            File::put($dir.'/recipe.json', (string) json_encode([
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'version' => $recipe->version,
                'status' => $recipe->status->value,
                'platforms' => $recipe->platforms,
                'aliases' => $recipe->aliases,
                'requires' => $recipe->requirements,
                'steps' => $recipe->steps,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $written++;
        }

        return ['written' => $written, 'path' => $destination];
    }
}
