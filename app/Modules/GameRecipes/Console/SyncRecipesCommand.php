<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Console;

use App\Modules\GameRecipes\Actions\SyncRecipes;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use Illuminate\Console\Command;

class SyncRecipesCommand extends Command
{
    protected $signature = 'lanomat:recipes:sync
        {source? : A directory, smb://host/share[/path], or git+https://...}
        {--prune : Delete recipes from this source that no longer exist}
        {--only= : Sync only one slug}
        {--json : Machine-readable output}';

    protected $description = 'Import or update game recipes from the bundled directory, an SMB share or a git repository.';

    public function handle(SyncRecipes $sync): int
    {
        $argument = $this->argument('source');
        $source = is_string($argument) && $argument !== '' ? $argument : (string) config('recipes.source');

        try {
            $result = $sync->handle(
                $source,
                (bool) $this->option('prune'),
                is_string($only = $this->option('only')) ? $only : null,
            );
        } catch (RecipeException $exception) {
            $this->error($exception->summary());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Recipes synced from %s: %d created, %d updated, %d pruned.',
            $result['source'],
            $result['created'],
            $result['updated'],
            $result['pruned'],
        ));

        foreach ($result['skipped'] as $problem) {
            $this->warn($problem);
        }

        return self::SUCCESS;
    }
}
