<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

/**
 * Is the native voice client that /talk/client offers the one built for this server? Reads
 * `manifest.json` that scripts/ops/build-client.sh wrote into the (read-only mounted) dist directory
 * and compares its `app_version` with the running app. Self-contained on purpose (config and a
 * JSON file only, no WebVoice classes): Preflight is a core module. Never `down`: a missing or old
 * client build does not stop anybody from using the site, it only costs the Desktop-App download.
 */
class ClientBuildCheck implements HealthCheck
{
    public function key(): string
    {
        return 'client_build';
    }

    public function label(): string
    {
        return __('preflight.checks.client_build');
    }

    public function run(): HealthResult
    {
        $dir = config('webvoice.native.dist_path');

        if (! is_string($dir) || $dir === '' || ! is_dir($dir)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        $manifest = $this->manifest(rtrim($dir, '/\\').'/manifest.json');

        if ($manifest === null) {
            return HealthResult::warn(__('preflight.messages.client_build_missing'));
        }

        $version = (string) config('app.version');
        $commit = config('app.commit');
        $sameCommit = ! is_string($commit) || $commit === '' || $commit === 'unknown' || $manifest['commit'] === $commit;

        if ($manifest['version'] === $version && $sameCommit) {
            return HealthResult::ok();
        }

        return HealthResult::warn(__('preflight.messages.client_build_stale', [
            'client' => $manifest['version'],
            'server' => $version,
        ]));
    }

    /** @return array{version: string, commit: string}|null */
    private function manifest(string $file): ?array
    {
        if (! is_file($file) || (int) @filesize($file) > 262144) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        if (! is_array($data) || ! is_string($data['app_version'] ?? null) || $data['app_version'] === '') {
            return null;
        }

        return [
            'version' => $data['app_version'],
            'commit' => is_string($data['commit'] ?? null) ? $data['commit'] : '',
        ];
    }
}
