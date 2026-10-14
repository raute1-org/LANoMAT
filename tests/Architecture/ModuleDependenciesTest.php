<?php

/**
 * Ratchet for the modular-monolith rule "modules talk via events and explicit
 * contracts". The baseline lists every cross-module reference that exists
 * today. A NEW edge fails the test (decouple it or consciously extend the
 * baseline); an edge that disappeared also fails so the baseline only ever
 * shrinks. Regenerate with: LANOMAT_ARCH_DUMP=1 ./vendor/bin/pest tests/Architecture
 */

/**
 * @return array<string, list<string>> module => sorted modules it references
 */
function moduleDependencyGraph(): array
{
    $root = dirname(__DIR__, 2).'/app/Modules';
    $graph = [];

    foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $module = basename($dir);
        $deps = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/App\\\\Modules\\\\([A-Za-z]+)\\\\/', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $other) {
                if ($other !== $module) {
                    $deps[$other] = true;
                }
            }
        }

        $names = array_keys($deps);
        sort($names);
        $graph[$module] = $names;
    }

    ksort($graph);

    return $graph;
}

$baselinePath = __DIR__.'/module-dependencies.php';

it('introduces no new cross-module dependencies', function () use ($baselinePath) {
    $actual = moduleDependencyGraph();

    if (getenv('LANOMAT_ARCH_DUMP') === '1') {
        file_put_contents($baselinePath, "<?php\n\nreturn ".var_export($actual, true).";\n");
    }

    /** @var array<string, list<string>> $baseline */
    $baseline = require $baselinePath;

    $added = [];
    $removed = [];

    foreach ($actual as $module => $deps) {
        foreach (array_diff($deps, $baseline[$module] ?? []) as $dep) {
            $added[] = "$module -> $dep";
        }
        foreach (array_diff($baseline[$module] ?? [], $deps) as $dep) {
            $removed[] = "$module -> $dep";
        }
    }

    expect($added)->toBe([], 'New cross-module dependencies — decouple via events/contracts or extend the baseline on purpose.');
    expect($removed)->toBe([], 'Dependencies were removed — shrink tests/Architecture/module-dependencies.php (LANOMAT_ARCH_DUMP=1).');
});

arch('bracket engine domain is pure (no framework, no IO)')
    ->expect('App\Modules\Tournaments\Domain')
    ->not->toUse(['Illuminate', 'App\Modules\Tournaments\Models', 'Livewire']);

it('declares only interfaces in module Contracts namespaces', function () {
    $offenders = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Modules/*/Contracts/*.php') ?: [] as $file) {
        if (! preg_match('/^interface\s/m', (string) file_get_contents($file))) {
            $offenders[] = basename(dirname($file, 2)).'/'.basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
