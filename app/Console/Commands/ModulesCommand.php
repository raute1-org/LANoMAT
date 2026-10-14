<?php

namespace App\Console\Commands;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

class ModulesCommand extends Command
{
    protected $signature = 'lanomat:modules {--json : Machine-readable output}';

    protected $description = 'Show the active module profile and what each module registered';

    public function handle(Modules $modules, Schedule $schedule): int
    {
        $report = [
            'profile' => (string) config('modules.profile'),
            'enabled' => array_map(fn (Module $m) => $m->key(), $modules->enabledModules()),
            'routes' => collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->values()->all(),
            'filament_resources' => array_values(Filament::getPanel('admin')->getResources()),
            'scheduled' => collect($schedule->events())
                ->map(fn ($e) => $e instanceof CallbackEvent ? (string) $e->description : (string) $e->command)
                ->values()->all(),
            'preflight' => collect(app()->tagged('preflight.checks'))
                ->map(fn (HealthCheck $c) => $c->key())->values()->all(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Profile: '.$report['profile']);
        $this->line('Enabled: '.implode(', ', $report['enabled']));
        $this->line('Disabled: '.implode(', ', array_map(
            fn (Module $m) => $m->key(),
            array_filter(Module::cases(), fn (Module $m) => ! $modules->enabled($m)),
        )));

        return self::SUCCESS;
    }
}
