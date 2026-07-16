<?php

use Illuminate\Support\Facades\Config;

/**
 * `config/reverb.php` is a plain array file: `env()` calls inside it are
 * resolved once, when Laravel first loads the config during bootstrapping,
 * not on every `config()` read. To assert the env-driven behaviour without
 * depending on boot-order tricks, these tests re-require the config file
 * directly (it has no side effects beyond returning an array) with the env
 * var set/unset, exactly as Laravel's own config loader would when booting
 * against a given environment.
 */
function loadReverbConfig(): array
{
    return require base_path('config/reverb.php');
}

it('honors REVERB_ALLOWED_ORIGINS when set', function () {
    Config::set('reverb.apps.apps.0.allowed_origins', null); // sanity: not read from live config

    putenv('REVERB_ALLOWED_ORIGINS=https://lan.example');

    try {
        $config = loadReverbConfig();
    } finally {
        putenv('REVERB_ALLOWED_ORIGINS');
    }

    expect($config['apps']['apps'][0]['allowed_origins'])->toBe(['https://lan.example']);
});

it('supports a comma-separated list of allowed origins', function () {
    putenv('REVERB_ALLOWED_ORIGINS=https://lan.example,https://admin.lan.example');

    try {
        $config = loadReverbConfig();
    } finally {
        putenv('REVERB_ALLOWED_ORIGINS');
    }

    expect($config['apps']['apps'][0]['allowed_origins'])->toBe([
        'https://lan.example',
        'https://admin.lan.example',
    ]);
});

it('defaults allowed_origins to a wildcard when unset', function () {
    putenv('REVERB_ALLOWED_ORIGINS'); // ensure unset

    $config = loadReverbConfig();

    expect($config['apps']['apps'][0]['allowed_origins'])->toBe(['*']);
});
