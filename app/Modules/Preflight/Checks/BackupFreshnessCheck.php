<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

/**
 * Reads the `.last-success` marker written by scripts/ops/backup.sh (a Unix
 * timestamp). OK below 26h, WARN above, DOWN above 50h (two missed nightly
 * runs). A missing marker is "not configured" outside production and a WARN
 * in production, where backups are expected.
 */
class BackupFreshnessCheck implements HealthCheck
{
    private const WARN_AFTER_HOURS = 26;

    private const DOWN_AFTER_HOURS = 50;

    public function key(): string
    {
        return 'backup';
    }

    public function label(): string
    {
        return __('preflight.checks.backup');
    }

    public function run(): HealthResult
    {
        $path = (string) config('preflight.backup_marker_path');
        $raw = ($path !== '' && is_file($path)) ? trim((string) @file_get_contents($path)) : null;

        if ($raw === null || $raw === '') {
            return app()->isProduction()
                ? HealthResult::warn(__('preflight.messages.backup_missing'))
                : HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        if (! ctype_digit($raw)) {
            return HealthResult::warn(__('preflight.messages.backup_unreadable'));
        }

        $ageHours = (now()->getTimestamp() - (int) $raw) / 3600;
        $message = __('preflight.messages.backup_age', ['hours' => (int) floor(max(0, $ageHours))]);

        return match (true) {
            $ageHours > self::DOWN_AFTER_HOURS => HealthResult::down($message),
            $ageHours > self::WARN_AFTER_HOURS => HealthResult::warn($message),
            default => HealthResult::ok(),
        };
    }
}
