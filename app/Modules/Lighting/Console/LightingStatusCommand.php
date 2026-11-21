<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Console;

use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Models\LightingSetting;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Console\Command;

class LightingStatusCommand extends Command
{
    protected $signature = 'lighting:status';

    protected $description = 'Show the light bridge health and the state of every light.';

    public function handle(LightingClient $client, Modules $modules): int
    {
        if (! $modules->enabled(Module::Lighting)) {
            $this->error(__('lighting.console.module_off'));

            return self::FAILURE;
        }

        $health = $client->health();

        if (! $health->reachable) {
            $this->warn(__('lighting.state.unreachable'));

            return self::FAILURE;
        }

        $this->info($health->healthy ? 'Bridge: ok' : 'Bridge: unhealthy');
        $this->line('Strobe: '.(LightingSetting::strobeAllowed() ? 'allowed' : 'locked'));

        $rows = array_map(fn ($f) => [
            $f->id,
            $f->state->hex ?? '-',
            $f->state->brightness ?? '-',
            $f->state->strobe ?? 0,
        ], $client->fixtures()->fixtures);

        $this->table(['Light', 'Hex', 'Brightness', 'Strobe'], $rows);

        return $health->healthy ? self::SUCCESS : self::FAILURE;
    }
}
