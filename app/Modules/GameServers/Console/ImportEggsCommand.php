<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Console;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\RepositoryEggs;
use Illuminate\Console\Command;

/**
 * Imports the eggs from `resources/pelican-eggs/` into the configured panel
 * (Application API `POST /api/application/eggs/import`, idempotent: the panel
 * upserts by egg uuid). When the application key has no write scope on eggs
 * the command prints the manual import steps and verifies through `listEggs()`
 * that the egg exists on the panel (matched by uuid), so a venue admin can do
 * the one-time UI import and still get a green check.
 */
class ImportEggsCommand extends Command
{
    protected $signature = 'gameservers:import-eggs
        {--check : Only verify that the repository eggs exist on the panel, import nothing}';

    protected $description = 'Import (or update) the LANoMAT Pelican eggs into the configured panel; prints manual steps when the key may not import.';

    public function handle(PelicanClient $client): int
    {
        $failed = false;
        $eggs = RepositoryEggs::all();

        foreach ($eggs as $egg) {
            if (! $this->option('check')) {
                try {
                    $imported = $client->importEgg($egg['content']);
                    $this->line("imported/updated {$egg['name']} ({$egg['uuid']}) as egg id {$imported->id}");

                    continue;
                } catch (GameServerException $e) {
                    if ($e->httpStatus !== 401 && $e->httpStatus !== 403) {
                        $this->error("{$egg['name']}: ".$e->getMessage());
                        $failed = true;

                        continue;
                    }

                    $this->warn("{$egg['name']}: the application key may not import eggs (HTTP {$e->httpStatus}).");
                    $this->manualSteps($egg['file']);
                }
            }

            if ($this->existsOnPanel($client, $egg['uuid'])) {
                $this->info("{$egg['name']} ({$egg['uuid']}) is already on the panel.");
            } else {
                $this->error("{$egg['name']} ({$egg['uuid']}) is missing on the panel.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function existsOnPanel(PelicanClient $client, string $uuid): bool
    {
        try {
            foreach ($client->listEggs() as $egg) {
                if ($egg->uuid === $uuid) {
                    return true;
                }
            }
        } catch (GameServerException $e) {
            $this->error('Could not list eggs: '.$e->getMessage());
        }

        return false;
    }

    private function manualSteps(string $file): void
    {
        $this->line('Manual import (one time, as a panel administrator):');
        $this->line("  1. Panel admin area > Eggs > Import from file, choose resources/pelican-eggs/{$file}.");
        $this->line('  2. Or give the application key write scope on Eggs and run this command again.');
        $this->line('  3. Run `php artisan gameservers:import-eggs --check` to verify.');
    }
}
