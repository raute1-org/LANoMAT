<?php

/**
 * Guard: every environment variable the MCP, GameRecipes (including the
 * launcher download page) and docs surfaces read is documented in .env.example.
 *
 * A fresh install copies .env.example; a switch that is missing there is
 * invisible in every runbook and silently falls back to a default. The test
 * scans the module config files instead of hard-coding a list, so a new
 * `env('MCP_…')` / `env('RECIPES_…')` fails this test without an edit here.
 *
 * `DOCS_URL` is deployment-only (compose profile `docs`, Traefik path routing
 * in docs-site/deploy/), so it has no config/*.php entry and is listed
 * explicitly. See docs/infra/mcp-ops.md.
 */
$root = dirname(__DIR__, 2);

$envExample = function () use ($root): string {
    static $source = null;

    return $source ??= (string) file_get_contents($root.'/.env.example');
};

$envNamesIn = function (string $configFile) use ($root): array {
    $source = (string) file_get_contents($root.'/config/'.$configFile);

    preg_match_all("/env\\(\\s*['\"]([A-Z0-9_]+)['\"]/", $source, $matches);

    return array_values(array_unique($matches[1]));
};

/** A line `NAME=` or a commented `# NAME=` counts: the examples document defaults. */
$isDocumented = function (string $name) use ($envExample): bool {
    return (bool) preg_match('/^\s*#?\s*'.preg_quote($name, '/').'=/m', $envExample());
};

it('reads the MCP and recipe config files', function () use ($envNamesIn) {
    expect($envNamesIn('mcp_agent.php'))->not->toBeEmpty();
    expect($envNamesIn('recipes.php'))->not->toBeEmpty();
});

it('documents every MCP env var in .env.example', function () use ($envNamesIn, $isDocumented) {
    $missing = array_values(array_filter($envNamesIn('mcp_agent.php'), fn (string $name): bool => ! $isDocumented($name)));

    expect($missing)->toBe([], 'Add these to .env.example (section "MCP server + pi agent"): '.implode(', ', $missing));
});

it('documents every game recipe env var in .env.example', function () use ($envNamesIn, $isDocumented) {
    $missing = array_values(array_filter($envNamesIn('recipes.php'), fn (string $name): bool => ! $isDocumented($name)));

    expect($missing)->toBe([], 'Add these to .env.example (section "Game recipes"): '.implode(', ', $missing));
});

it('documents every launcher env var in .env.example', function () use ($envNamesIn, $isDocumented) {
    $missing = array_values(array_filter($envNamesIn('launcher.php'), fn (string $name): bool => ! $isDocumented($name)));

    expect($missing)->toBe([], 'Add these to .env.example (section "Launcher downloads"): '.implode(', ', $missing));
});

it('documents the docs deployment switch in .env.example', function () use ($isDocumented) {
    expect($isDocumented('DOCS_URL'))->toBeTrue();
});

it('keeps the MCP and recipe names in the prefixed namespaces', function () use ($envNamesIn) {
    $names = [...$envNamesIn('mcp_agent.php'), ...$envNamesIn('recipes.php')];

    $unprefixed = array_values(array_filter(
        $names,
        fn (string $name): bool => ! str_starts_with($name, 'MCP_') && ! str_starts_with($name, 'RECIPES_'),
    ));

    expect($unprefixed)->toBe([], 'MCP/recipe config should use MCP_* / RECIPES_* names: '.implode(', ', $unprefixed));
});
