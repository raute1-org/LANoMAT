<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\DependencyUpdates;
use App\Modules\Preflight\Support\HealthResult;

/**
 * Pinned third-party images against their latest upstream release. Reads only the cache filled by
 * `lanomat:check-updates`, so it never waits on the network, and is never `down`: an outdated
 * component is a heads-up before the event, not an outage.
 */
class DependencyUpdatesCheck implements HealthCheck
{
    public function __construct(private readonly DependencyUpdates $updates) {}

    public function key(): string
    {
        return 'dependency_updates';
    }

    public function label(): string
    {
        return __('preflight.checks.dependency_updates');
    }

    public function run(): HealthResult
    {
        $rows = array_filter($this->updates->report(), fn (array $row): bool => $row['pinned'] !== null && $row['latest'] !== null);

        if ($rows === []) {
            return HealthResult::skipped(__('preflight.messages.updates_unknown'));
        }

        $behind = array_filter($rows, fn (array $row): bool => $row['behind'] !== null);

        if ($behind === []) {
            return HealthResult::ok();
        }

        $list = implode(', ', array_map(
            fn (array $row): string => $row['label'].' '.$row['pinned'].' → '.$row['latest'],
            $behind,
        ));

        return HealthResult::warn(__('preflight.messages.updates_behind', ['components' => $list]));
    }
}
