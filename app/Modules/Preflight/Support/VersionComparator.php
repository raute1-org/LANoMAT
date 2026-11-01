<?php

namespace App\Modules\Preflight\Support;

/**
 * A deliberately small SemVer-ish comparator for image tags and release names: `v1.13.11`, `1.5.939`,
 * `1.27-alpine`, `v2.8`, `16-alpine`. Anything without a leading number (`latest`, `alpine`) is ignored.
 */
final class VersionComparator
{
    /**
     * @return list<int>|null up to three numeric parts, null if the tag carries no version
     */
    public static function parse(string $tag): ?array
    {
        if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/i', trim($tag), $m) !== 1) {
            return null;
        }

        $parts = [];

        foreach (array_slice($m, 1) as $part) {
            if ($part === '') {
                break;
            }

            $parts[] = (int) $part;
        }

        return $parts;
    }

    /**
     * How far `$pinned` trails `$latest`: `major`, `minor`, or null when it is on the latest minor line
     * (patch releases do not count), ahead of it, or either side is not a version. A pinned tag
     * that names no minor (`16-alpine`) is only compared on the major.
     */
    public static function behind(string $pinned, string $latest): ?string
    {
        $have = self::parse($pinned);
        $want = self::parse($latest);

        if ($have === null || $want === null) {
            return null;
        }

        if ($have[0] !== $want[0]) {
            return $have[0] < $want[0] ? 'major' : null;
        }

        if (! isset($have[1]) || ! isset($want[1])) {
            return null;
        }

        return $have[1] < $want[1] ? 'minor' : null;
    }
}
