<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

/**
 * Is the native voice client that /talk/client offers the one built for this server? Reads
 * `manifest.json` that scripts/ops/build-client.sh wrote into the (read-only mounted) dist directory
 * and compares it with the running app: the same `app_version` and commit is `ok`; so is a build for the
 * same native-client API version (a server update that does not change the API needs no client rebuild).
 * Self-contained on purpose (config and a
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

        $serverApi = $this->serverApiVersion();

        if ($manifest['api'] !== null && $serverApi !== null && $manifest['api'] === $serverApi) {
            return HealthResult::ok(__('preflight.messages.client_build_compatible', [
                'client' => $manifest['version'],
                'api' => $serverApi,
            ]));
        }

        return HealthResult::warn(__('preflight.messages.client_build_stale', [
            'client' => $manifest['version'],
            'server' => $version,
        ]));
    }

    /** The server's native-client API version (config, set by the WebVoice module's config file). */
    private function serverApiVersion(): ?int
    {
        $value = config('webvoice.native.api_version');

        return is_int($value) ? $value : null;
    }

    /** @return array{version: string, commit: string, api: int|null}|null */
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
            'api' => is_int($data['api_version'] ?? null) ? $data['api_version'] : null,
        ];
    }
}
