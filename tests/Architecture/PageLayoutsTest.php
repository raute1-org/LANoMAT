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

it('wraps every app/profile page in the shared PageContainer', function () use ($table, $pages, $root) {
    $kinds = $table();

    $kindFor = function (string $name) use ($kinds): string {
        if (isset($kinds[$name])) {
            return $kinds[$name];
        }

        foreach ($kinds as $key => $kind) {
            if (str_ends_with($key, '/') && str_starts_with($name, $key)) {
                return $kind;
            }
        }

        return 'bare';
    };

    $missing = array_values(array_filter($pages(), function (string $name) use ($kindFor, $root): bool {
        if (! in_array($kindFor($name), ['app', 'profile'], true)) {
            return false;
        }

        $source = (string) file_get_contents($root.'/resources/js/pages/'.$name.'.vue');

        return ! str_contains($source, '<PageContainer');
    }));

    expect($missing)->toBe([], 'Wrap these pages in <PageContainer width="..."> (see docs/design.md "Page anatomy & layout rules").');
});

it('keeps raw max-w/mx-auto wrappers out of app pages', function () use ($pages, $root) {
    $offenders = array_values(array_filter($pages(), function (string $name) use ($root): bool {
        if (str_starts_with($name, 'settings/') || str_starts_with($name, 'auth/') || str_starts_with($name, 'Screen/') || str_starts_with($name, 'Overlay/') || $name === 'Welcome') {
            return false;
        }

        $source = (string) file_get_contents($root.'/resources/js/pages/'.$name.'.vue');
        $template = substr($source, (int) strpos($source, '<template>'));

        return (bool) preg_match('/<(main|div)\s+class="[^"]*mx-auto[^"]*max-w-/', $template);
    }));

    expect($offenders)->toBe([], 'Use <PageContainer width> instead of a hand-rolled page wrapper.');
});
