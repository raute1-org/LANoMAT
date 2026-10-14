<?php

/**
 * Ratchet for the modular-monolith rule "modules talk via events and explicit
 * contracts". The baseline lists every cross-module reference that exists
 * today. A NEW edge fails the test (decouple it or consciously extend the
 * baseline); an edge that disappeared also fails so the baseline only ever
 * shrinks. Regenerate with: LANOMAT_ARCH_DUMP=1 ./vendor/bin/pest tests/Architecture
 */

/**
 * Module graph from PHP tokens, so comments/docblocks never count and both
 * `use`/FQCN names and class-string literals ('App\\Modules\\X\\Y') are seen.
 *
 * @var Closure(): array<string, list<string>> $moduleDependencyGraph
 */
$moduleDependencyGraph = function (): array {
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

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) {
                    continue;
                }

                if (preg_match('/App\\\\{1,2}Modules\\\\{1,2}([A-Za-z]+)\\\\/', $token[1], $m) && $m[1] !== $module) {
                    $deps[$m[1]] = true;
                }
            }
        }

        $names = array_keys($deps);
        sort($names);
        $graph[$module] = $names;
    }

    ksort($graph);

    return $graph;
};

$baselinePath = __DIR__.'/module-dependencies.php';

it('introduces no new cross-module dependencies', function () use ($baselinePath, $moduleDependencyGraph) {
    $actual = $moduleDependencyGraph();

    if (getenv('LANOMAT_ARCH_DUMP') === '1') {
        file_put_contents($baselinePath, "<?php\n\nreturn ".var_export($actual, true).";\n");
        $this->markTestSkipped('Baseline regenerated — review the diff, run pint, then re-run without LANOMAT_ARCH_DUMP.');
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
    ->toOnlyUse('App\Modules\Tournaments\Domain');

it('declares only interfaces in module Contracts namespaces', function () {
    $offenders = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Modules/*/Contracts/{,*/}*.php', GLOB_BRACE) ?: [] as $file) {
        if (! preg_match('/^(?:readonly\s+)?interface\s/m', (string) file_get_contents($file))) {
            $offenders[] = substr($file, strlen(dirname(__DIR__, 2).'/app/Modules/'));
        }
    }

    expect($offenders)->toBe([]);
});

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
