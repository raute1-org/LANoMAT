<?php

namespace App\Modules\Preflight\Support;

use App\Modules\Preflight\Contracts\LatestRelease;
use Illuminate\Support\Facades\Cache;

/**
 * The watched components: pinned tags read from the compose files, latest releases cached. Only
 * `refresh()` talks to the upstream; `report()` is cache-only, so the Preflight run stays fast.
 */
class DependencyUpdates
{
    public function __construct(private readonly LatestRelease $releases) {}

    /**
     * Looks every component with a repo up and caches the answer. A failed lookup keeps the previous
     * cached value (if any) instead of erasing it.
     *
     * @return array<string, bool> component key => lookup succeeded
     */
    public function refresh(): array
    {
        $outcome = [];

        foreach ($this->components() as $key => $component) {
            if ($component['repo'] === null) {
                continue;
            }

            $tag = $this->releases->latest($component['repo']);

            if ($tag !== null) {
                Cache::put($this->cacheKey($key), $tag, now()->addHours((int) config('updates.cache_hours', 12)));
            }

            $outcome[$key] = $tag !== null;
        }

        return $outcome;
    }

    /**
     * @return list<array{key: string, label: string, pinned: string|null, latest: string|null, behind: string|null}>
     */
    public function report(): array
    {
        $rows = [];

        foreach ($this->components() as $key => $component) {
            $pinned = $this->pinnedTag($component['image']);
            $latest = $component['repo'] === null ? null : Cache::get($this->cacheKey($key));
            $latest = is_string($latest) ? $latest : null;

            $rows[] = [
                'key' => $key,
                'label' => $component['label'],
                'pinned' => $pinned,
                'latest' => $latest,
                'behind' => $pinned !== null && $latest !== null ? VersionComparator::behind($pinned, $latest) : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, array{label: string, image: string, repo: string|null}> */
    private function components(): array
    {
        $components = [];

        foreach ((array) config('updates.components', []) as $key => $component) {
            $components[(string) $key] = [
                'label' => (string) ($component['label'] ?? $key),
                'image' => (string) ($component['image'] ?? ''),
                'repo' => isset($component['repo']) && $component['repo'] !== '' ? (string) $component['repo'] : null,
            ];
        }

        return $components;
    }

    private function pinnedTag(string $image): ?string
    {
        if ($image === '') {
            return null;
        }

        foreach ((array) config('updates.compose_files', []) as $file) {
            $path = base_path((string) $file);

            if (! is_file($path) || ($contents = file_get_contents($path)) === false) {
                continue;
            }

            if (preg_match('/^\s*image:\s*["\']?'.preg_quote($image, '/').':([^\s"\'#]+)/m', $contents, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function cacheKey(string $component): string
    {
        return 'updates.latest.'.$component;
    }
}
