<?php

use App\Modules\GameRecipes\Support\LauncherBuilds;
use App\Modules\Preflight\Checks\LauncherBuildCheck;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * Publish a launcher dist directory (files + manifest.json) and return its path.
 *
 * @param  list<array{name: string, os: string, arch?: string, kind?: string, variant?: string, content?: string, signed?: bool|null}>  $files
 * @param  array<string, mixed>  $extra  extra/overriding top-level manifest keys
 */
function preflightLauncherDist(array $files, array $extra = []): string
{
    $dir = sys_get_temp_dir().'/lanomat-pf-launcher-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $entries = [];

    foreach ($files as $file) {
        $content = $file['content'] ?? 'launcher-binary-'.$file['name'];
        file_put_contents($dir.'/'.$file['name'], $content);
        $entries[] = [
            'name' => $file['name'],
            'os' => $file['os'],
            'arch' => $file['arch'] ?? 'x86_64',
            'kind' => $file['kind'] ?? 'app',
            'variant' => $file['variant'] ?? 'archive',
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'signed' => $file['signed'] ?? null,
        ];
    }

    file_put_contents($dir.'/manifest.json', json_encode([
        'app_version' => 'v1.4.2',
        'commit' => 'abc123def456',
        'built_at' => now()->subDay()->toIso8601String(),
        'files' => $entries,
        ...$extra,
    ]));

    test()->launcherCheckDirs = [...test()->launcherCheckDirs, $dir];

    return $dir;
}

/** The usual release: a Linux tarball and a Windows installer. */
function preflightLauncherRelease(array $extra = []): string
{
    return preflightLauncherDist([
        ['name' => 'lanomat-launcher-v1.4.2-linux-x86_64.tar.gz', 'os' => 'linux', 'content' => 'linux-tarball'],
        ['name' => 'lanomat-launcher-v1.4.2-windows-x86_64-setup.exe', 'os' => 'windows', 'variant' => 'installer', 'content' => 'windows-setup', 'signed' => false],
    ], $extra);
}

function preflightLauncherCheck(string $dir): LauncherBuildCheck
{
    return new LauncherBuildCheck(new LauncherBuilds($dir));
}

beforeEach(function () {
    $this->launcherCheckDirs = [];
});

afterEach(function () {
    foreach ($this->launcherCheckDirs as $dir) {
        File::deleteDirectory($dir);
    }
});

it('is registered as launcher_build while GameRecipes is enabled', function () {
    $keys = collect(app()->tagged('preflight.checks'))->map(fn ($check) => $check->key());

    expect($keys)->toContain('launcher_build');
});

it('warns, never fails, when there is no dist directory or no manifest', function () {
    $absent = sys_get_temp_dir().'/lanomat-pf-launcher-absent-'.bin2hex(random_bytes(4));

    $missingDir = preflightLauncherCheck($absent)->run();

    expect($missingDir->status->value)->toBe('warn')
        ->and($missingDir->message)->toContain('scripts/ops/build-launcher.sh');

    $empty = sys_get_temp_dir().'/lanomat-pf-launcher-empty-'.bin2hex(random_bytes(4));
    mkdir($empty);
    $this->launcherCheckDirs = [...$this->launcherCheckDirs, $empty];

    $noManifest = preflightLauncherCheck($empty)->run();

    expect($noManifest->status->value)->toBe('warn')
        ->and($noManifest->message)->toContain('scripts/ops/build-launcher.sh');
});

it('fails when the manifest is malformed', function () {
    $dir = preflightLauncherRelease();
    file_put_contents($dir.'/manifest.json', '{broken');

    $result = preflightLauncherCheck($dir)->run();

    expect($result->status->value)->toBe('down')
        ->and($result->message)->toContain('scripts/ops/build-launcher.sh');
});

it('fails when the manifest no longer lists a published file', function () {
    $dir = preflightLauncherRelease();

    foreach (glob($dir.'/*.tar.gz') ?: [] as $file) {
        unlink($file);
    }

    $result = preflightLauncherCheck($dir)->run();

    expect($result->status->value)->toBe('down')
        ->and($result->message)->toContain('lanomat-launcher-v1.4.2-linux-x86_64.tar.gz');
});

it('fails when a listed file changed size', function () {
    $dir = preflightLauncherRelease();
    file_put_contents($dir.'/lanomat-launcher-v1.4.2-linux-x86_64.tar.gz', 'tampered and much longer than before');

    $result = preflightLauncherCheck($dir)->run();

    expect($result->status->value)->toBe('down')
        ->and($result->message)->toContain('lanomat-launcher-v1.4.2-linux-x86_64.tar.gz');
});

it('fails when a listed file keeps its size but changes content', function () {
    $dir = preflightLauncherRelease();
    $file = $dir.'/lanomat-launcher-v1.4.2-windows-x86_64-setup.exe';
    $original = (string) file_get_contents($file);
    file_put_contents($file, strrev($original));

    $result = preflightLauncherCheck($dir)->run();

    expect($result->status->value)->toBe('down')
        ->and($result->message)->toContain('lanomat-launcher-v1.4.2-windows-x86_64-setup.exe');
});

it('is ok with a fresh Linux and Windows release', function () {
    $dir = preflightLauncherRelease();

    $result = preflightLauncherCheck($dir)->run();

    expect($result->status->value)->toBe('ok')
        ->and($result->message)->toContain('v1.4.2');
});

it('warns when only one operating system has an app build', function () {
    $dir = preflightLauncherDist([
        ['name' => 'lanomat-launcher-v1.4.2-linux-x86_64.tar.gz', 'os' => 'linux', 'content' => 'linux-tarball'],
    ]);

    $result = preflightLauncherCheck($dir)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toContain('Linux')->toContain('Windows');
});

it('warns when only CLI builds are published', function () {
    $dir = preflightLauncherDist([
        ['name' => 'lanomat-launcher-cli-linux-x86_64.tar.gz', 'os' => 'linux', 'kind' => 'cli', 'content' => 'cli'],
    ]);

    $result = preflightLauncherCheck($dir)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toContain('Kein Launcher-Build');
});

it('warns when the build is older than 30 days', function () {
    $dir = preflightLauncherRelease(['built_at' => now()->subDays(31)->toIso8601String()]);

    $result = preflightLauncherCheck($dir)->run();

    expect($result->status->value)->toBe('warn')
        ->and($result->message)->toContain('älter als 30 Tage')
        ->and($result->message)->toContain('scripts/ops/build-launcher.sh');
});

it('is still ok at 29 days', function () {
    $dir = preflightLauncherRelease(['built_at' => now()->subDays(29)->toIso8601String()]);

    expect(preflightLauncherCheck($dir)->run()->status->value)->toBe('ok');
});

it('reads the directory from launcher.dist_path when resolved from the container', function () {
    $dir = preflightLauncherRelease();
    Config::set('launcher.dist_path', $dir);

    expect(app(LauncherBuildCheck::class)->run()->status->value)->toBe('ok');
});
