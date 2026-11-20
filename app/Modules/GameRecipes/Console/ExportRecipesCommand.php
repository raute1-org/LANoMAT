<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Console;

use App\Modules\GameRecipes\Actions\ExportRecipes;
use Illuminate\Console\Command;

class ExportRecipesCommand extends Command
{
    protected $signature = 'lanomat:recipes:export
        {path : Destination directory for the skill bundle}
        {--all : Include draft recipes too}';

    protected $description = 'Export recipes as portable Agent Skill directories (SKILL.md + recipe.json).';

    public function handle(ExportRecipes $export): int
    {
        $path = $this->argument('path');
        $result = $export->handle(is_string($path) ? $path : '', ! (bool) $this->option('all'));

        $this->info(sprintf('Exported %d recipes to %s.', $result['written'], $result['path']));

        return self::SUCCESS;
    }
}
