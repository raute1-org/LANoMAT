<?php

namespace App\Modules\Preflight\Console;

use App\Modules\Preflight\Actions\RunPreflight;
use Illuminate\Console\Command;

class PreflightCommand extends Command
{
    protected $signature = 'lanomat:preflight';

    protected $description = 'Probe every internal + external system and report a traffic-light status.';

    private const GLYPH = ['ok' => '<fg=green>●</> OK', 'warn' => '<fg=yellow>●</> WARN', 'down' => '<fg=red>●</> DOWN', 'skipped' => '<fg=gray>○</> SKIP'];

    public function handle(RunPreflight $run): int
    {
        $results = $run->handle();

        $this->table(
            ['System', 'Status', 'Info'],
            array_map(fn (array $r): array => [
                $r['label'],
                self::GLYPH[$r['status']] ?? $r['status'],
                $r['message'],
            ], $results),
        );

        $down = array_filter($results, fn (array $r): bool => $r['status'] === 'down');

        if ($down !== []) {
            $this->error(count($down).' system(s) down.');

            return self::FAILURE;
        }

        $this->info('Preflight ok.');

        return self::SUCCESS;
    }
}
