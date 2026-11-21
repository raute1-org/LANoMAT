<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Console;

use App\Modules\GameRecipes\Actions\ExportRecipes;
use Illuminate\Console\Command;

class ExportRecipesCommand extends Command
{
    protected $signature = 'lanomat:recipes:export
        {path : Destination directory for the export}
        {--all : Include draft recipes too}
        {--format=skills : "skills" for Agent Skill folders (recipe.json) or "repository" for resources/recipes layout (recipe.yaml + SKILL.md)}';

    protected $description = 'Export recipes as Agent Skill directories (SKILL.md + recipe.json) or in the repository layout (recipe.yaml + SKILL.md).';

    public function handle(ExportRecipes $export): int
    {
        $path = $this->argument('path');
        $path = is_string($path) ? $path : '';
        $format = $this->option('format');
        $format = is_string($format) ? $format : ExportRecipes::FORMAT_SKILLS;

        if (! in_array($format, ExportRecipes::FORMATS, true)) {
            $this->error(sprintf(
                'Unknown format "%s". Use "%s" or "%s".',
                $format,
                ExportRecipes::FORMAT_SKILLS,
                ExportRecipes::FORMAT_REPOSITORY,
            ));

            return self::FAILURE;
        }

        $result = $export->handle($path, ! (bool) $this->option('all'), $format);

        $this->info(sprintf('Exported %d recipes to %s.', $result['written'], $result['path']));

        return self::SUCCESS;
    }
}
