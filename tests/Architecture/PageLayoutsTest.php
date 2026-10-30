<?php

/**
 * Guard: every Inertia page component must have an explicit layout decision in
 * resources/js/lib/pageLayouts.ts, and every decision must still match a page.
 */
$root = dirname(__DIR__, 2);

$table = function () use ($root): array {
    $source = (string) file_get_contents($root.'/resources/js/lib/pageLayouts.ts');
    preg_match_all("/^\s*'?([A-Za-z\/]+)'?:\s*'(app|settings|auth|profile|bare)',/m", $source, $m);

    return array_combine($m[1], $m[2]);
};

$pages = function () use ($root): array {
    $base = $root.'/resources/js/pages/';
    $names = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'vue') {
            $names[] = substr(substr($file->getPathname(), strlen($base)), 0, -4);
        }
    }

    sort($names);

    return $names;
};

it('parses a non-empty layout table', function () use ($table) {
    expect($table())->not->toBeEmpty();
});

it('gives every Inertia page an explicit layout decision', function () use ($table, $pages) {
    $keys = array_keys($table());

    $undecided = array_values(array_filter($pages(), function (string $name) use ($keys): bool {
        foreach ($keys as $key) {
            if ($key === $name || (str_ends_with($key, '/') && str_starts_with($name, $key))) {
                return false;
            }
        }

        return true;
    }));

    expect($undecided)->toBe([], 'Add these pages to resources/js/lib/pageLayouts.ts (see docs/design.md "Page anatomy & layout rules").');
});

it('has no stale layout entries that match no page', function () use ($table, $pages) {
    $names = $pages();

    $stale = array_values(array_filter(array_keys($table()), function (string $key) use ($names): bool {
        foreach ($names as $name) {
            if ($key === $name || (str_ends_with($key, '/') && str_starts_with($name, $key))) {
                return false;
            }
        }

        return true;
    }));

    expect($stale)->toBe([]);
});
