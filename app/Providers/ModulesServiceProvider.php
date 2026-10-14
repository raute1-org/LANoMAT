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

            // Matches only the literal `wayfinder:generate`: abbreviated command names, Artisan::call from
            // another command, or an active route:cache bypass it. Acceptable: CI, the Vite plugin and the
            // Dockerfile all use the full name.
            return new Modules(
                (string) config('modules.profile'),
                (array) config('modules.overrides'),
                exposeAllRoutes: $app->runningInConsole() && in_array('wayfinder:generate', $argv, true),
            );
        });
    }
}
