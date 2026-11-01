<?php

use App\Modules\Preflight\Checks\ClientBuildCheck;
use Illuminate\Support\Facades\File;

function manifestDir(?string $version, string $commit = 'abc123def456'): string
{
    $dir = sys_get_temp_dir().'/lanomat-pf-'.bin2hex(random_bytes(6));
    mkdir($dir);
    if ($version !== null) {
        file_put_contents($dir.'/manifest.json', json_encode(['app_version' => $version, 'commit' => $commit, 'files' => []]));
    }
    test()->pfDirs = [...test()->pfDirs, $dir];
    config(['webvoice.native.dist_path' => $dir]);

    return $dir;
}

beforeEach(function () {
    $this->pfDirs = [];
    config(['app.version' => 'm12-5-gabcdef0', 'app.commit' => 'abc123def456']);
});

afterEach(function () {
    foreach ($this->pfDirs as $dir) {
        File::deleteDirectory($dir);
    }
});

it('is registered as client_build while WebVoice is enabled', function () {
    $keys = collect(app()->tagged('preflight.checks'))->map(fn ($c) => $c->key());

    expect($keys)->toContain('client_build');
});

it('is skipped when the dist directory does not exist', function () {
    config(['webvoice.native.dist_path' => sys_get_temp_dir().'/lanomat-absent-'.bin2hex(random_bytes(4))]);

    expect(app(ClientBuildCheck::class)->run()->status->value)->toBe('skipped');

    config(['webvoice.native.dist_path' => null]);
    expect(app(ClientBuildCheck::class)->run()->status->value)->toBe('skipped');
});

it('is ok when the manifest matches the running version', function () {
    manifestDir('m12-5-gabcdef0');

    expect(app(ClientBuildCheck::class)->run()->status->value)->toBe('ok');
});

it('warns, never fails, when there is no build, and names the script', function () {
    manifestDir(null);

    $result = app(ClientBuildCheck::class)->run();

    expect($result->status->value)->toBe('warn')->and($result->message)->toContain('scripts/ops/build-client.sh');
});

it('warns when the build is for another version or commit', function () {
    manifestDir('m12-4-g1111111');
    $older = app(ClientBuildCheck::class)->run();

    expect($older->status->value)->toBe('warn')
        ->and($older->message)->toContain('m12-4-g1111111')->toContain('m12-5-gabcdef0')->toContain('scripts/ops/build-client.sh');

    manifestDir('m12-5-gabcdef0', 'fffffffffff0');
    expect(app(ClientBuildCheck::class)->run()->status->value)->toBe('warn');
});

it('warns on an unreadable manifest', function () {
    $dir = manifestDir(null);
    file_put_contents($dir.'/manifest.json', '{broken');

    expect(app(ClientBuildCheck::class)->run()->status->value)->toBe('warn');
});
