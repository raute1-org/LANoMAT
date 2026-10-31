<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Invariants of the committed LANoMAT egg fork. The panel imports eggs by uuid
 * (upsert), so a wrong uuid or a leftover upstream update_url would overwrite the
 * stock egg or let a panel-side update replace the fork.
 */
const STOCK_GMOD_EGG_UUID = '60ef81d4-30a2-4d98-ab64-f59c69e2f915';

// Pelican renames these on import (and rewrites the startup line), see EggVariable::RESERVED_ENV_NAMES.
const PELICAN_RESERVED_ENV_NAMES = ['P_SERVER_UUID', 'P_SERVER_ALLOCATION_LIMIT', 'SERVER_MEMORY', 'SERVER_IP', 'SERVER_PORT', 'ENV', 'HOME', 'USER', 'STARTUP', 'MODIFIED_STARTUP', 'SERVER_UUID', 'UUID', 'INTERNAL_IP', 'HOSTNAME', 'TERM', 'LANG', 'PWD', 'TZ', 'TIMEZONE'];

function lanomatEgg(): array
{
    return Yaml::parseFile(resource_path('pelican-eggs/lanomat-garrys-mod.yaml'));
}

it('is a PLCN_v3 egg with its own uuid and no upstream update url', function () {
    $egg = lanomatEgg();

    expect($egg['meta']['version'])->toBe('PLCN_v3')
        ->and($egg['uuid'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($egg['uuid'])->not->toBe(STOCK_GMOD_EGG_UUID)
        ->and($egg['meta'])->not->toHaveKey('update_url')
        ->and($egg['name'])->toBe('Garrys Mod (LANoMAT)');
});

it('declares the extra variables and keeps the api key hidden', function () {
    $variables = collect(lanomatEgg()['variables'])->keyBy('env_variable');

    expect($variables->keys()->all())->toContain('SERVER_HOSTNAME', 'STEAM_WEB_API_KEY', 'EXTRA_ARGS', 'STEAM_ACC', 'WORKSHOP_ID')
        ->and($variables['STEAM_WEB_API_KEY']['user_viewable'])->toBeFalse()
        ->and($variables['STEAM_WEB_API_KEY']['user_editable'])->toBeFalse()
        ->and($variables['STEAM_WEB_API_KEY']['rules'])->toContain('nullable')
        ->and($variables['SERVER_HOSTNAME']['rules'])->toContain('required')
        ->and($variables['EXTRA_ARGS']['rules'])->toContain('nullable');
});

it('uses no env variable name that pelican reserves', function () {
    $names = array_column(lanomatEgg()['variables'], 'env_variable');

    expect(array_intersect($names, PELICAN_RESERVED_ENV_NAMES))->toBe([]);
});

it('declares every variable the startup line references and guards the optional ones', function () {
    $egg = lanomatEgg();
    $startup = $egg['startup_commands']['Default'];
    $declared = array_column($egg['variables'], 'env_variable');
    $builtin = ['SERVER_PORT'];

    preg_match_all('/\{\{([A-Z_]+)\}\}/', $startup, $placeholders);
    preg_match_all('/\$\{?([A-Z_]+)\}?/', $startup, $shellRefs);

    foreach (array_unique([...$placeholders[1], ...$shellRefs[1]]) as $name) {
        expect(in_array($name, [...$declared, ...$builtin], true))->toBeTrue("startup references undeclared {$name}");
    }

    expect($startup)->toContain('[ -n "$STEAM_WEB_API_KEY" ]')
        ->and($startup)->toContain('[ -n "$STEAM_ACC" ]')
        ->and($startup)->toContain('[ -n "$WORKSHOP_ID" ]')
        ->and($startup)->toContain('"$SERVER_HOSTNAME"')
        ->and($startup)->toContain('+exec lanomat_hostname.cfg')
        ->and($startup)->not->toContain('+hostname');
});

it('fails the install when the game directory is missing and sets no hostname in server.cfg', function () {
    $script = lanomatEgg()['scripts']['installation']['script'];

    expect($script)->toContain('exit 1')
        ->and($script)->toContain('/mnt/server/garrysmod')
        ->and(preg_match('/^\s*hostname\s/m', $script))->toBe(0);
});

it('keeps the MIT attribution of the upstream egg', function () {
    $raw = file_get_contents(resource_path('pelican-eggs/lanomat-garrys-mod.yaml'));

    expect($raw)->toContain('MIT License')->and($raw)->toContain('pelican-eggs/games-steamcmd');
});
