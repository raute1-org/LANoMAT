<?php

use App\Support\Modules\Module;

/**
 * Guard: the agent-facing setup documents stay in step with the code.
 *
 * docs/setup/agent-setup.md and AGENTS.md are the first thing a stranger's coding agent reads. They rot the
 * moment a module, a script or a document moves. This test turns the three cheapest kinds of rot into a
 * failing build:
 *
 *  - a module exists in the Module enum but the guide's module reference does not list it (or marks it
 *    core/optional wrongly),
 *  - a relative link points at a file that is gone,
 *  - a repository path the guide names in backticks (scripts/, docker/, docs/, ...) no longer exists.
 *
 * When this fails, update docs/setup/agent-setup.md in the same pull request; see AGENTS.md, "Keep the
 * setup documents current".
 */
$root = dirname(__DIR__, 2);

$guide = fn (): string => (string) file_get_contents($root.'/docs/setup/agent-setup.md');

/** @return list<string> repo-relative paths of the documents that guide a setup agent */
$documents = function () use ($root): array {
    $files = ['AGENTS.md'];

    foreach (glob($root.'/docs/setup/*.md') ?: [] as $path) {
        $files[] = 'docs/setup/'.basename($path);
    }

    return $files;
};

it('lists every module in the setup guide module reference', function () use ($guide) {
    preg_match_all('/^\| `([a-z_]+)` \|/m', $guide(), $matches);
    $documented = $matches[1];

    $missing = array_values(array_diff(array_map(fn (Module $m): string => $m->key(), Module::cases()), $documented));
    $unknown = array_values(array_diff($documented, array_map(fn (Module $m): string => $m->key(), Module::cases())));

    expect($missing)->toBe([], 'Add these modules to the module reference in docs/setup/agent-setup.md.')
        ->and($unknown)->toBe([], 'These keys are in the module reference but not in the Module enum.');
});

it('marks exactly the core modules as core in the module reference', function () use ($guide) {
    preg_match_all('/^\| `([a-z_]+)` \| (yes)? ?\|/m', $guide(), $matches, PREG_SET_ORDER);

    $documentedCore = [];
    foreach ($matches as $row) {
        if (($row[2] ?? '') === 'yes') {
            $documentedCore[] = $row[1];
        }
    }

    $actualCore = array_map(
        fn (Module $m): string => $m->key(),
        array_values(array_filter(Module::cases(), fn (Module $m): bool => $m->isCore())),
    );
    sort($documentedCore);
    sort($actualCore);

    expect($documentedCore)->toBe($actualCore);
});

it('links only to files that exist', function () use ($root, $documents) {
    $broken = [];

    foreach ($documents() as $document) {
        $source = (string) file_get_contents($root.'/'.$document);
        preg_match_all('/\]\(([^)#\s]+)(?:#[^)]*)?\)/', $source, $links);

        foreach ($links[1] as $link) {
            if (str_starts_with($link, 'http') || str_starts_with($link, 'mailto:')) {
                continue;
            }

            $target = realpath(dirname($root.'/'.$document).'/'.$link);
            if ($target === false) {
                $broken[] = $document.' -> '.$link;
            }
        }
    }

    expect($broken)->toBe([]);
});

it('names only repository paths that exist', function () use ($root, $documents) {
    // Generated or machine-local files that a clean checkout does not have.
    $generated = ['docker/livekit/livekit.yaml'];
    $broken = [];

    foreach ($documents() as $document) {
        $source = (string) file_get_contents($root.'/'.$document);
        preg_match_all('/`((?:scripts|docker|docs|docs-site|app|config|tests|resources|database|lang|\.github)\/[A-Za-z0-9_.\/-]*)`/', $source, $paths);

        foreach (array_unique($paths[1]) as $path) {
            if (! in_array($path, $generated, true) && ! file_exists($root.'/'.$path)) {
                $broken[] = $document.' -> '.$path;
            }
        }
    }

    expect($broken)->toBe([]);
});

it('keeps AGENTS.md pointing at the setup guide', function () use ($root) {
    expect((string) file_get_contents($root.'/AGENTS.md'))->toContain('docs/setup/agent-setup.md')
        ->and(file_exists($root.'/docs/setup/agent-setup.md'))->toBeTrue();
});
