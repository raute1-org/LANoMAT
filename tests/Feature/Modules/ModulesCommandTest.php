<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

require_once __DIR__.'/ModulesProcess.php';

it('reports the full profile with every module enabled', function () {
    $report = modulesReport('full');

    expect($report['profile'])->toBe('full')
        ->and($report['enabled'])->toContain('jukebox', 'voice', 'discord');
});

it('reports the core profile with only core modules', function () {
    $report = modulesReport('core');

    expect($report['enabled'])->toEqualCanonicalizing([
        'events', 'registration', 'seating', 'teams', 'games',
        'tournaments', 'identity', 'notifications', 'preflight',
    ]);
});

it('fails to boot with a clear message on an invalid configuration', function () {
    $process = new Process(
        [PHP_BINARY, 'artisan', 'lanomat:modules', '--json'],
        base_path(),
        ['MODULES_PROFILE' => 'core', 'MODULE_EVENTS' => 'false', 'APP_ENV' => 'testing'],
    );
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())->toContain('Core module "events" cannot be disabled.');
});

it('resolves the same modules from a cached config', function () {
    // A private cache path keeps the real bootstrap/cache/config.php untouched.
    $cachePath = sys_get_temp_dir().'/lanomat-modules-config-'.bin2hex(random_bytes(4)).'.php';
    $env = ['MODULES_PROFILE' => 'core', 'MODULE_JUKEBOX' => 'true', 'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $cachePath];

    try {
        (new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), $env))->mustRun();

        // No MODULE_* in the environment this time: only the cached config may supply them.
        $report = modulesReport('full', ['MODULES_PROFILE' => 'full', 'APP_CONFIG_CACHE' => $cachePath]);
        expect($report['enabled'])->toContain('jukebox')
            ->and($report['profile'])->toBe('core'); // cached value wins over env
    } finally {
        @unlink($cachePath);
    }
});

it('keeps all routes visible to wayfinder under the core profile', function () {
    // Remove the generated jukebox helpers so the final assertion proves regeneration.
    File::deleteDirectory(resource_path('js/routes/jukebox'));

    try {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'wayfinder:generate', '--with-form'],
            base_path(),
            ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing'],
        );
        $process->mustRun();

        expect(file_exists(resource_path('js/routes/jukebox/index.ts')))->toBeTrue();
    } finally {
        // Never leave the helpers missing after a mid-test failure.
        (new Process([PHP_BINARY, 'artisan', 'wayfinder:generate', '--with-form'], base_path(), ['MODULES_PROFILE' => 'full', 'APP_ENV' => 'testing']))->run();
    }
});

it('treats an empty MODULE_<KEY> env value as unset', function () {
    expect(modulesReport('core', ['MODULE_JUKEBOX' => ''])['enabled'])->not->toContain('jukebox')
        ->and(modulesReport('full', ['MODULE_VOICE' => ''])['enabled'])->toContain('voice');
});
