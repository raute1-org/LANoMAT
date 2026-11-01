<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

use App\Modules\WebVoice\NativeClient\Support\NativeClientConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The client builds this server offers: reads and VALIDATES `manifest.json` written by
 * scripts/ops/build-client.sh in the dist directory (`webvoice.native.dist_path`, mounted read-only).
 *
 * The manifest is the only source of file names. A name is accepted only if it is a plain file name
 * (no separators, no leading dot, no `..`), is listed in the manifest, exists as a regular file and
 * still has the listed size; everything else is "not a build". The SHA-256 is compared only on
 * request ({@see self::verified()}), because hashing 50 MB per download is not worth it.
 */
class ClientBuilds
{
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    private const VERSION = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private const COMMIT = '/^[A-Za-z0-9]{4,64}$/';

    private const SHA256 = '/^[a-f0-9]{64}$/';

    private const OS = ['linux', 'windows', 'macos'];

    private const ARCH = ['x86_64', 'aarch64'];

    private const KIND = ['app', 'cli'];

    private bool $loaded = false;

    private ?ClientManifest $manifest = null;

    public function __construct(private readonly ?string $directory = null) {}

    /** Whether a dist directory is configured and exists (it is optional: the app works without). */
    public function configured(): bool
    {
        $dir = $this->directory();

        return $dir !== null && is_dir($dir);
    }

    /** The validated manifest, or `null` when there is none, it is malformed or unsafe. */
    public function current(): ?ClientManifest
    {
        if (! $this->loaded) {
            $this->manifest = $this->load();
            $this->loaded = true;
        }

        return $this->manifest;
    }

    /**
     * Cheap summary for pages that only need to know "is there an app for me": the manifest
     * version, whether it matches this server, and the OSes with a GUI build. Cached for 60 s;
     * the manifest's mtime and size are part of the key, so republishing shows up immediately.
     *
     * @return array{version: string|null, current: bool, oses: list<string>}
     */
    public function offer(): array
    {
        $dir = $this->directory();
        $file = $dir === null ? null : $dir.DIRECTORY_SEPARATOR.'manifest.json';

        if ($file === null || ! is_file($file)) {
            return ['version' => null, 'current' => false, 'oses' => []];
        }

        clearstatcache(true, $file);
        $key = 'webvoice.client.offer.'.hash('xxh3', implode('|', [$file, (int) @filemtime($file), (int) @filesize($file), self::serverVersion(), (string) self::serverCommit()]));

        /** @var array{version: string|null, current: bool, oses: list<string>} */
        return Cache::remember($key, 60, function (): array {
            $manifest = $this->current();
            $apps = array_filter($manifest->builds ?? [], fn (ClientBuild $b): bool => $b->kind === 'app');

            return [
                'version' => $manifest?->appVersion,
                'current' => $manifest !== null && $apps !== [] && $this->matchesServer(),
                'oses' => array_values(array_unique(array_map(fn (ClientBuild $b): string => $b->os, $apps))),
            ];
        });
    }

    /** The version string of the running server. */
    public static function serverVersion(): string
    {
        return (string) config('app.version');
    }

    public static function serverCommit(): ?string
    {
        $commit = config('app.commit');

        return is_string($commit) && $commit !== '' ? $commit : null;
    }

    /**
     * Is the published client the one built for this server? Exactly the same version string, and,
     * when both sides know their commit, the same commit. A `dev` server never matches a release.
     */
    public function matchesServer(): bool
    {
        $manifest = $this->current();

        if ($manifest === null || $manifest->appVersion !== self::serverVersion()) {
            return false;
        }

        $commit = self::serverCommit();

        return $commit === null || $commit === 'unknown' || $manifest->commit === $commit;
    }

    /** @return list<ClientBuild> builds for one platform (an empty list: there is none) */
    public function forPlatform(string $os, string $arch): array
    {
        $found = [];

        foreach ($this->offered() as $build) {
            if ($build->os === $os && $build->arch === $arch) {
                $found[] = $build;
            }
        }

        return $found;
    }

    /** The manifest entry for a requested name, or `null`. The request value is only ever compared, never used as a path. */
    public function find(string $name): ?ClientBuild
    {
        foreach ($this->offered() as $build) {
            if (hash_equals($build->name, $name)) {
                return $build;
            }
        }

        return null;
    }

    /** Absolute path of a listed build (existence and size were checked when the manifest was read). */
    public function pathFor(ClientBuild $build): string
    {
        return (string) $this->directory().DIRECTORY_SEPARATOR.$build->name;
    }

    /** Whether the file's SHA-256 equals the manifest's. Reads the whole file. */
    public function verified(ClientBuild $build): bool
    {
        $hash = @hash_file('sha256', $this->pathFor($build));

        return is_string($hash) && hash_equals($build->sha256, $hash);
    }

    /** @return list<ClientBuild> */
    private function offered(): array
    {
        $manifest = $this->current();

        return $manifest === null ? [] : $manifest->builds;
    }

    private function directory(): ?string
    {
        $dir = $this->directory ?? NativeClientConfig::distPath();

        return $dir === null ? null : rtrim($dir, '/\\');
    }

    private function load(): ?ClientManifest
    {
        $dir = $this->directory();

        if ($dir === null || ! is_dir($dir)) {
            return null;
        }

        $file = $dir.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($file) || is_link($file) || (int) @filesize($file) > 262144) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);

            return is_array($data) ? $this->parse($data, $dir) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<mixed>  $data */
    private function parse(array $data, string $dir): ?ClientManifest
    {
        $version = $data['app_version'] ?? null;
        $commit = $data['commit'] ?? null;
        $api = $data['api_version'] ?? null;
        $builtAt = $data['built_at'] ?? null;
        $files = $data['files'] ?? null;

        if (! is_string($version) || preg_match(self::VERSION, $version) !== 1
            || ! is_string($commit) || preg_match(self::COMMIT, $commit) !== 1
            || ! is_int($api) || $api < 1
            || ! is_string($builtAt) || ! is_array($files) || count($files) > 64) {
            return null;
        }

        $builtAt = CarbonImmutable::parse($builtAt);
        $builds = [];

        foreach ($files as $entry) {
            // One malformed entry makes the whole manifest untrustworthy.
            $build = is_array($entry) ? $this->entry($entry) : null;

            if ($build === null) {
                return null;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$build->name;

            // A listed file that is missing, a link, or of another size is simply not offered.
            if (is_file($path) && ! is_link($path) && @filesize($path) === $build->size) {
                $builds[] = $build;
            }
        }

        return new ClientManifest($version, $commit, $api, $builtAt, $builds);
    }

    /** @param  array<mixed>  $entry */
    private function entry(array $entry): ?ClientBuild
    {
        $name = $entry['name'] ?? null;
        $os = $entry['os'] ?? null;
        $arch = $entry['arch'] ?? null;
        $kind = $entry['kind'] ?? null;
        $size = $entry['size'] ?? null;
        $sha = $entry['sha256'] ?? null;

        if (! is_string($name) || preg_match(self::NAME, $name) !== 1 || str_contains($name, '..')
            || $name === 'manifest.json'
            || ! is_string($os) || ! in_array($os, self::OS, true)
            || ! is_string($arch) || ! in_array($arch, self::ARCH, true)
            || ! is_string($kind) || ! in_array($kind, self::KIND, true)
            || ! is_int($size) || $size < 0
            || ! is_string($sha) || preg_match(self::SHA256, $sha) !== 1) {
            return null;
        }

        return new ClientBuild($name, $os, $arch, $kind, $size, $sha);
    }
}
