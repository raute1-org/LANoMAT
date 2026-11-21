<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The LANoMAT Launcher builds this server offers: reads and VALIDATES
 * `manifest.json` from the launcher dist directory (`clients-dist/launcher`, mounted
 * read-only in compose), written by `scripts/ops/build-launcher.sh`.
 *
 * The manifest is the only source of file names. A name is accepted only if it is a
 * plain file name (no separator, no leading dot, no `..`), is listed in the manifest,
 * exists as a regular file directly in the dist directory and still has the listed
 * size; everything else is "not a build". A missing, unreadable or malformed manifest
 * means "nothing published" — this class never throws, so the page always renders
 * (its empty state) instead of failing.
 *
 * Path override, in this order: constructor argument, `config('launcher.dist_path')`
 * (`LAUNCHER_DIST_PATH`, or set directly in a test), `base_path('clients-dist/launcher')`.
 *
 * @phpstan-type LauncherBuild array{name: string, os: string, arch: string, kind: string, variant: string, size: int, sha256: string, signed: bool|null}
 * @phpstan-type LauncherManifest array{version: string, commit: string, builtAt: string, files: list<LauncherBuild>}
 */
final class LauncherBuilds
{
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    private const VERSION = '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/';

    private const COMMIT = '/^[A-Za-z0-9]{4,64}$/';

    private const SHA256 = '/^[a-f0-9]{64}$/';

    /** @var list<string> */
    private const OS = ['linux', 'windows', 'macos'];

    /** @var list<string> */
    private const ARCH = ['x86_64', 'aarch64'];

    /** @var list<string> */
    private const KIND = ['app', 'cli'];

    /** @var list<string> */
    private const VARIANT = ['installer', 'portable', 'archive'];

    private const MAX_MANIFEST_BYTES = 262144;

    private const MAX_FILES = 64;

    private bool $loaded = false;

    /** @var LauncherManifest|null */
    private ?array $manifest = null;

    public function __construct(private readonly ?string $directory = null) {}

    /** The dist directory the builds are read from. */
    public function basePath(): string
    {
        if ($this->directory !== null && $this->directory !== '') {
            return rtrim($this->directory, '/\\');
        }

        $configured = config('launcher.dist_path');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/\\');
        }

        return rtrim(base_path('clients-dist/launcher'), '/\\');
    }

    /**
     * The validated manifest, or `null` when there is none, it is malformed or unsafe.
     *
     * @return LauncherManifest|null
     */
    public function manifest(): ?array
    {
        if (! $this->loaded) {
            $this->manifest = $this->load();
            $this->loaded = true;
        }

        return $this->manifest;
    }

    /**
     * One build per OS — the best of what the manifest offers for it: the app before a
     * CLI, an installer before a portable file before a plain archive. Linux, Windows,
     * macOS order. An empty list means "nothing published".
     *
     * @return list<LauncherBuild>
     */
    public function builds(): array
    {
        $best = [];

        foreach ($this->files() as $build) {
            $current = $best[$build['os']] ?? null;

            if ($current === null || $this->rank($build) < $this->rank($current)) {
                $best[$build['os']] = $build;
            }
        }

        $ordered = [];

        foreach (self::OS as $os) {
            if (isset($best[$os])) {
                $ordered[] = $best[$os];
            }
        }

        return $ordered;
    }

    /** @return list<LauncherBuild> every file the validated manifest offers */
    public function files(): array
    {
        $manifest = $this->manifest();

        return $manifest === null ? [] : $manifest['files'];
    }

    /**
     * The manifest entry for a requested name, or `null`. The request value is only ever
     * compared with that list, never turned into a path.
     *
     * @return LauncherBuild|null
     */
    public function find(string $name): ?array
    {
        foreach ($this->files() as $build) {
            if (hash_equals($build['name'], $name)) {
                return $build;
            }
        }

        return null;
    }

    /**
     * Absolute path of a listed build, or `null` when it is not (or no longer) a regular
     * file directly inside the dist directory with the listed size. `realpath()` resolves
     * a swapped-in symlink, and the parent-directory comparison keeps the file inside the
     * base directory even if the name itself ever slipped through.
     *
     * @param  LauncherBuild  $build
     */
    public function pathFor(array $build): ?string
    {
        $dir = $this->basePath();

        $path = $dir.DIRECTORY_SEPARATOR.$build['name'];
        $base = realpath($dir);
        $real = realpath($path);

        if ($base === false || $real === false || is_link($path) || ! is_file($real)) {
            return null;
        }

        if (dirname($real) !== $base || @filesize($real) !== $build['size']) {
            return null;
        }

        return $real;
    }

    /**
     * Whether the file still has the SHA-256 the manifest lists. Reads the whole file.
     *
     * @param  LauncherBuild  $build
     */
    public function verified(array $build): bool
    {
        $handle = $this->open($build);

        if ($handle === null) {
            return false;
        }

        fclose($handle);

        return true;
    }

    /**
     * Open a listed build for reading and prove that the OPEN handle holds exactly the
     * bytes the manifest lists: its size from `fstat()` and its SHA-256 from its own
     * content, then rewind it. A caller that streams from the handle therefore cannot
     * serve anything but the bytes that were hashed — there is no check-then-open gap in
     * which the file could be swapped (TOCTOU). The caller closes the handle.
     *
     * @param  LauncherBuild  $build
     * @return resource|null a rewound binary handle, or `null` when the file is missing,
     *                       not the listed size or no longer the listed checksum
     */
    public function open(array $build)
    {
        $path = $this->pathFor($build);

        if ($path === null) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $stat = fstat($handle);

        if ($stat === false || $stat['size'] !== $build['size']) {
            fclose($handle);

            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $handle);

        // A file that grew or shrank while being read hashes differently, so this one
        // comparison covers both "same size, other bytes" and a concurrent write.
        if (! hash_equals($build['sha256'], hash_final($context)) || ! rewind($handle)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @return LauncherManifest|null */
    private function load(): ?array
    {
        $dir = $this->basePath();

        if (! is_dir($dir)) {
            return null;
        }

        $file = $dir.DIRECTORY_SEPARATOR.'manifest.json';
        clearstatcache(true, $file);

        if (! is_file($file) || is_link($file) || (int) @filesize($file) > self::MAX_MANIFEST_BYTES) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $this->parse($data, $dir) : null;
    }

    /**
     * @param  array<mixed>  $data
     * @return LauncherManifest|null
     */
    private function parse(array $data, string $dir): ?array
    {
        $version = $data['app_version'] ?? null;
        $commit = $data['commit'] ?? null;
        $builtAt = $data['built_at'] ?? null;
        $files = $data['files'] ?? null;

        if (! is_string($version) || preg_match(self::VERSION, $version) !== 1
            || ! is_string($commit) || preg_match(self::COMMIT, $commit) !== 1
            || ! is_string($builtAt)
            || ! is_array($files) || $files === [] || count($files) > self::MAX_FILES) {
            return null;
        }

        try {
            $when = CarbonImmutable::parse($builtAt);
        } catch (Throwable) {
            return null;
        }

        $builds = [];

        foreach ($files as $file) {
            // One malformed entry makes the whole manifest untrustworthy.
            $build = is_array($file) ? $this->entry($file) : null;

            if ($build === null) {
                return null;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$build['name'];

            // Listed but missing, a link, or of another size: simply not offered.
            if (is_file($path) && ! is_link($path) && @filesize($path) === $build['size']) {
                $builds[] = $build;
            }
        }

        if ($builds === []) {
            return null;
        }

        usort($builds, fn (array $a, array $b): int => [$this->osRank($a['os']), $this->rank($a), $a['name']]
            <=> [$this->osRank($b['os']), $this->rank($b), $b['name']]);

        return [
            'version' => $version,
            'commit' => $commit,
            'builtAt' => $when->toIso8601String(),
            'files' => $builds,
        ];
    }

    /**
     * @param  array<mixed>  $entry
     * @return LauncherBuild|null
     */
    private function entry(array $entry): ?array
    {
        $name = $entry['name'] ?? null;
        $os = $entry['os'] ?? null;
        $arch = $entry['arch'] ?? null;
        $kind = $entry['kind'] ?? null;
        // Manifests that predate the field describe a plain archive.
        $variant = $entry['variant'] ?? 'archive';
        $size = $entry['size'] ?? null;
        $sha = $entry['sha256'] ?? null;
        $signed = $entry['signed'] ?? null;

        if (! is_string($name) || preg_match(self::NAME, $name) !== 1
            || str_contains($name, '..') || $name === 'manifest.json'
            || ! is_string($os) || ! in_array($os, self::OS, true)
            || ! is_string($arch) || ! in_array($arch, self::ARCH, true)
            || ! is_string($kind) || ! in_array($kind, self::KIND, true)
            || ! is_string($variant) || ! in_array($variant, self::VARIANT, true)
            || ! $this->variantFits($variant, $name, $os, $kind)
            || ! is_int($size) || $size < 0
            || ! is_string($sha) || preg_match(self::SHA256, $sha) !== 1
            || ($signed !== null && ! is_bool($signed))) {
            return null;
        }

        return [
            'name' => $name,
            'os' => $os,
            'arch' => $arch,
            'kind' => $kind,
            'variant' => $variant,
            'size' => $size,
            'sha256' => $sha,
            'signed' => $signed,
        ];
    }

    /**
     * A setup or portable file is the Windows app as an .exe, named for what it is; an
     * archive may be anything (the Linux tarball, a CLI binary, a zip).
     */
    private function variantFits(string $variant, string $name, string $os, string $kind): bool
    {
        return match ($variant) {
            'installer' => $os === 'windows' && $kind === 'app' && str_ends_with($name, '-setup.exe'),
            'portable' => $os === 'windows' && $kind === 'app' && str_ends_with($name, '-portable.exe'),
            default => true,
        };
    }

    /** @param  LauncherBuild  $build */
    private function rank(array $build): int
    {
        return ($build['kind'] === 'app' ? 0 : 10)
            + match ($build['variant']) {
                'installer' => 0,
                'portable' => 1,
                default => 2,
            };
    }

    private function osRank(string $os): int
    {
        $index = array_search($os, self::OS, true);

        return $index === false ? count(self::OS) : $index;
    }
}
