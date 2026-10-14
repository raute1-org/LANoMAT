<?php

namespace App\Providers;

use App\Support\Modules\Modules;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Modules::class, function (Application $app): Modules {
            /** @var list<string> $argv */
            $argv = $_SERVER['argv'] ?? [];

            return new Modules(
                (string) config('modules.profile'),
                (array) config('modules.overrides'),
                exposeAllRoutes: $app->runningInConsole() && in_array('wayfinder:generate', $argv, true),
            );
        });
    }
}
