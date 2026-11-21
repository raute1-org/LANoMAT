<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\RecipeYamlExporter;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Writes recipes to disk in one of two shapes:
 *
 * - `skills`: portable Agent Skill directories (`<slug>/SKILL.md` +
 *   `recipe.json`), for an offline installer bundle or a CI artefact, so a Pi
 *   install can be seeded without a running server;
 * - `repository`: the catalog's own file format (`<slug>/recipe.yaml` +
 *   `<slug>/SKILL.md`), so a recipe can travel into git as a pull request.
 */
final class ExportRecipes
{
    public const FORMAT_SKILLS = 'skills';

    public const FORMAT_REPOSITORY = 'repository';

    /** @var list<string> */
    public const FORMATS = [self::FORMAT_SKILLS, self::FORMAT_REPOSITORY];

    public function __construct(
        private readonly RecipeYamlExporter $exporter,
    ) {}

    /** @return array{written: int, path: string} */
    public function handle(string $destination, bool $publishedOnly = true, string $format = self::FORMAT_SKILLS): array
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown export format "%s". Use "%s" or "%s".',
                $format,
                self::FORMAT_SKILLS,
                self::FORMAT_REPOSITORY,
            ));
        }

        File::ensureDirectoryExists($destination, 0755);

        $recipes = GameRecipe::query()
            ->when($publishedOnly, fn ($query) => $query->published())
            ->orderBy('slug')
            ->get();

        $written = 0;

        foreach ($recipes as $recipe) {
            $dir = rtrim($destination, '/').'/'.$recipe->slug;
            File::ensureDirectoryExists($dir, 0755);

            if ($format === self::FORMAT_REPOSITORY) {
                $exported = $this->exporter->export($recipe);
                File::put($dir.'/recipe.yaml', $exported['yaml']);
                File::put($dir.'/SKILL.md', $exported['skill']);
                $written++;

                continue;
            }

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
