<?php

namespace App\Modules\Preflight\Console;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Notifications\SystemUnhealthy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class HealthWatchCommand extends Command
{
    protected $signature = 'lanomat:health-watch';

    protected $description = 'Bell the orga when a system is down or jobs have failed (edge-triggered).';

    public function handle(RunPreflight $run): int
    {
        $results = $run->handle();

        $downLabels = array_values(array_map(
            fn (array $r): string => $r['label'],
            array_filter($results, fn (array $r): bool => $r['status'] === 'down' || ($r['key'] === 'failed_jobs' && $r['status'] === 'warn')),
        ));

        $unhealthy = $downLabels !== [];
        $wasUnhealthy = (bool) Cache::get('preflight.watch_state', false);
        Cache::put('preflight.watch_state', $unhealthy, now()->addDay());

        if ($unhealthy && ! $wasUnhealthy) {
            $orgas = User::query()->whereIn('role', [Role::Orga, Role::Admin])->get();
            Notification::send($orgas, new SystemUnhealthy($downLabels));
        }

        return self::SUCCESS;
    }
}
