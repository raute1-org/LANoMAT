<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\GameRecipes\Support\LauncherBuilds;
use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Is the LANoMAT Launcher that `/launcher` offers present, complete and recent? Reads
 * `clients-dist/launcher/manifest.json` through {@see LauncherBuilds} (never a second copy of its
 * validation rules) and reports what the Orga has to fix:
 *
 * - no manifest at all: WARN — like the native client build, a missing launcher costs the download,
 *   it does not stop anybody from using the site.
 * - manifest unreadable/malformed, a listed file missing, or a published file whose size/SHA-256 no
 *   longer matches: DOWN. The download would hand out a broken installer.
 * - an app build for only one of Linux/Windows: WARN (the usual release ships both).
 * - `built_at` older than 30 days: WARN — the binaries are probably older than the server.
 *
 * The directory comes from {@see LauncherBuilds} (constructor argument, `launcher.dist_path`, then
 * `clients-dist/launcher`), so tests only pass a temp directory.
 *
 * @phpstan-import-type LauncherBuild from LauncherBuilds
 */
class LauncherBuildCheck implements HealthCheck
{
    private const STALE_AFTER_DAYS = 30;

    /** @var list<string> */
    private const REQUIRED_OS = ['linux', 'windows'];

    /** @var array<string, string> */
    private const OS_LABELS = [
        'linux' => 'Linux',
        'windows' => 'Windows',
        'macos' => 'macOS',
    ];

    public function __construct(private readonly ?LauncherBuilds $builds = null) {}

    public function key(): string
    {
        return 'launcher_build';
    }

    public function label(): string
    {
        return __('preflight.checks.launcher_build');
    }

    public function run(): HealthResult
    {
        $builds = $this->builds ?? new LauncherBuilds;
        $dir = $builds->basePath();
        $file = $dir.DIRECTORY_SEPARATOR.'manifest.json';
        clearstatcache(true, $file);

        if (! is_file($file)) {
            return HealthResult::warn(__('preflight.messages.launcher_build_missing'));
        }

        $manifest = $builds->manifest();

        if ($manifest === null) {
            return HealthResult::down(__('preflight.messages.launcher_build_invalid'));
        }

        $broken = [
            ...$this->droppedNames($file, $builds->files()),
            ...$this->unverifiedNames($builds),
        ];

        if ($broken !== []) {
            return HealthResult::down(__('preflight.messages.launcher_build_changed', [
                'files' => implode(', ', array_unique($broken)),
            ]));
        }

        $published = [];

        foreach ($builds->files() as $build) {
            if ($build['kind'] === 'app') {
                $published[$build['os']] = true;
            }
        }

        $missing = array_values(array_diff(self::REQUIRED_OS, array_keys($published)));

        if ($missing !== []) {
            if (count($missing) === count(self::REQUIRED_OS)) {
                return HealthResult::warn(__('preflight.messages.launcher_build_missing'));
            }

            $have = array_values(array_diff(self::REQUIRED_OS, $missing));

            return HealthResult::warn(__('preflight.messages.launcher_build_partial', [
                'have' => $this->osLabels($have),
                'missing' => $this->osLabels($missing),
            ]));
        }

        $version = $manifest['version'];
        $builtAt = CarbonImmutable::parse($manifest['builtAt']);

        if ($builtAt->lt(CarbonImmutable::now()->subDays(self::STALE_AFTER_DAYS))) {
            return HealthResult::warn(__('preflight.messages.launcher_build_stale', [
                'version' => $version,
                'date' => $builtAt->format('d.m.Y'),
            ]));
        }

        return HealthResult::ok(__('preflight.messages.launcher_build_ok', [
            'version' => $version,
            'date' => $builtAt->format('d.m.Y'),
        ]));
    }

    /**
     * Names the raw manifest lists that {@see LauncherBuilds} did not publish — a file that vanished
     * or changed size. Not validation: the raw top-level `files[].name` values are only compared
     * with the already validated ones, so a tampered installer is named even if another build of the
     * same OS still hides the drop.
     *
     * @param  list<LauncherBuild>  $published
     * @return list<string>
     */
    private function droppedNames(string $file, array $published): array
    {
        $raw = @file_get_contents($file);

        if (! is_string($raw)) {
            return [];
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($data) || ! is_array($data['files'] ?? null)) {
            return [];
        }

        $listed = [];

        foreach ($data['files'] as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;

            if (is_string($name)) {
                $listed[] = $name;
            }
        }

        $known = array_map(fn (array $build): string => $build['name'], $published);

        return array_values(array_diff($listed, $known));
    }

    /**
     * Published builds whose SHA-256 no longer matches the manifest (same size, other content).
     *
     * @return list<string>
     */
    private function unverifiedNames(LauncherBuilds $builds): array
    {
        $broken = [];

        foreach ($builds->files() as $build) {
            if (! $builds->verified($build)) {
                $broken[] = $build['name'];
            }
        }

        return $broken;
    }

    /** @param  list<string>  $os */
    private function osLabels(array $os): string
    {
        return implode(' und ', array_map(
            fn (string $name): string => self::OS_LABELS[$name] ?? $name,
            $os,
        ));
    }
}
