<?php

namespace App\Modules\Preflight\Console;

use App\Modules\Preflight\Support\DependencyUpdates;
use Illuminate\Console\Command;

class CheckUpdatesCommand extends Command
{
    protected $signature = 'lanomat:check-updates';

    protected $description = 'Looks up the latest releases of the pinned third-party infrastructure and shows what is outdated.';

    public function handle(DependencyUpdates $updates): int
    {
        $outcome = $updates->refresh();

        $this->table(
            ['Component', 'Pinned', 'Latest', 'Status'],
            array_map(function (array $row) use ($outcome): array {
                $status = match (true) {
                    $row['pinned'] === null => 'not pinned',
                    $row['latest'] === null && isset($outcome[$row['key']]) => 'lookup failed',
                    $row['latest'] === null => 'no source',
                    $row['behind'] === 'major' => 'behind (major)',
                    $row['behind'] === 'minor' => 'behind (minor)',
                    default => 'ok',
                };

                return [$row['label'], $row['pinned'] ?? '-', $row['latest'] ?? '-', $status];
            }, $updates->report()),
        );

        $failed = array_keys(array_filter($outcome, fn (bool $ok): bool => ! $ok));

        if ($failed !== []) {
            $this->warn('Lookup failed for: '.implode(', ', $failed).'.');
        }

        // Only ever a heads-up, never a failing exit code: a scheduler run must not look broken over this.
        return self::SUCCESS;
    }
}
