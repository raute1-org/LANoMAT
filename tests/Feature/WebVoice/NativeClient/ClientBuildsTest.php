<?php

declare(strict_types=1);

use App\Modules\WebVoice\Support\ClientBuilds;
use Illuminate\Support\Facades\File;

/**
 * A temp dist directory with a manifest and real files.
 *
 * @param  array<string, mixed>  $overrides  merged into the manifest
 * @param  array<string, string>  $files  name => content (also listed in the manifest as linux app builds)
 */
function distDir(array $overrides = [], array $files = ['lanomat-voice-app-v1-linux-x86_64.tar.gz' => 'app-bytes']): string
{
    $dir = sys_get_temp_dir().'/lanomat-dist-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $entries = [];
    foreach ($files as $name => $content) {
        file_put_contents($dir.'/'.$name, $content);
        $entries[] = ['name' => $name, 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app', 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
    }

    file_put_contents($dir.'/manifest.json', json_encode(array_merge([
        'app_version' => 'v1',
        'commit' => 'abc123def456',
        'api_version' => 1,
        'built_at' => '2026-11-01T10:00:00Z',
        'files' => $entries,
    ], $overrides)));

    test()->dirs = [...test()->dirs, $dir];

    return $dir;
}

beforeEach(function () {
    $this->dirs = [];
    config(['app.version' => 'v1', 'app.commit' => 'abc123def456']);
});

afterEach(function () {
    foreach ($this->dirs as $dir) {
        File::deleteDirectory($dir);
    }
});

it('reads a valid manifest and offers its files', function () {
    $builds = new ClientBuilds(distDir());

    expect($builds->configured())->toBeTrue()
        ->and($builds->current()->appVersion)->toBe('v1')
        ->and($builds->current()->commit)->toBe('abc123def456')
        ->and($builds->current()->apiVersion)->toBe(1)
        ->and($builds->matchesServer())->toBeTrue()
        ->and($builds->forPlatform('linux', 'x86_64'))->toHaveCount(1)
        ->and($builds->forPlatform('windows', 'x86_64'))->toBe([])
        ->and($builds->forPlatform('macos', 'aarch64'))->toBe([])
        ->and($builds->find('lanomat-voice-app-v1-linux-x86_64.tar.gz')->kind)->toBe('app');
});

it('has no manifest for a missing directory, a missing file or garbage', function () {
    expect((new ClientBuilds('/nonexistent/'.bin2hex(random_bytes(4))))->current())->toBeNull();

    $empty = sys_get_temp_dir().'/lanomat-dist-'.bin2hex(random_bytes(6));
    mkdir($empty);
    $this->dirs = [...$this->dirs, $empty];
    expect((new ClientBuilds($empty))->current())->toBeNull();

    file_put_contents($empty.'/manifest.json', '{not json');
    expect((new ClientBuilds($empty))->current())->toBeNull();

    file_put_contents($empty.'/manifest.json', '"a string"');
    expect((new ClientBuilds($empty))->current())->toBeNull();
});

it('rejects the whole manifest when any entry carries an unsafe name', function (string $name) {
    $dir = distDir();
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
    $manifest['files'][0]['name'] = $name;
    file_put_contents($dir.'/manifest.json', json_encode($manifest));

    expect((new ClientBuilds($dir))->current())->toBeNull();
})->with([
    'traversal' => '../../etc/passwd',
    'parent only' => '..',
    'dotdot inside' => 'a..b.tar.gz',
    'slash' => 'sub/dir.tar.gz',
    'backslash' => 'sub\\dir.tar.gz',
    'leading dot' => '.hidden',
    'absolute' => '/etc/passwd',
    'nul byte' => "a.tar.gz\0.txt",
    'space' => 'a b.tar.gz',
    'the manifest itself' => 'manifest.json',
    'empty' => '',
    'too long' => str_repeat('a', 200),
]);

it('rejects malformed fields', function (callable $break) {
    $dir = distDir();
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
    $break($manifest);
    file_put_contents($dir.'/manifest.json', json_encode($manifest));

    expect((new ClientBuilds($dir))->current())->toBeNull();
})->with([
    'version with slash' => fn (&$m) => $m['app_version'] = 'v1/../x',
    'version not a string' => fn (&$m) => $m['app_version'] = 5,
    'bad commit' => fn (&$m) => $m['commit'] = 'zz zz',
    'api version 0' => fn (&$m) => $m['api_version'] = 0,
    'no files' => fn (&$m) => $m['files'] = 'x',
    'unknown os' => fn (&$m) => $m['files'][0]['os'] = 'plan9',
    'unknown arch' => fn (&$m) => $m['files'][0]['arch'] = 'mips',
    'unknown kind' => fn (&$m) => $m['files'][0]['kind'] = 'installer',
    'negative size' => fn (&$m) => $m['files'][0]['size'] = -1,
    'float size' => fn (&$m) => $m['files'][0]['size'] = 1.5,
    'short sha' => fn (&$m) => $m['files'][0]['sha256'] = 'abc',
    'uppercase sha' => fn (&$m) => $m['files'][0]['sha256'] = strtoupper(str_repeat('a', 64)),
    'unparsable date' => fn (&$m) => $m['built_at'] = 'not a date',
]);

it('does not offer a listed file that is missing, a symlink or of another size', function () {
    $dir = distDir(files: ['one-linux-x86_64.tar.gz' => 'aaaa', 'two-linux-x86_64.tar.gz' => 'bbbb', 'three-linux-x86_64.tar.gz' => 'cccc']);
    unlink($dir.'/one-linux-x86_64.tar.gz');
    file_put_contents($dir.'/two-linux-x86_64.tar.gz', 'longer than listed');
    unlink($dir.'/three-linux-x86_64.tar.gz');
    file_put_contents($dir.'/target.bin', 'cccc');
    symlink($dir.'/target.bin', $dir.'/three-linux-x86_64.tar.gz');

    $builds = new ClientBuilds($dir);

    expect($builds->current())->not->toBeNull()
        ->and($builds->current()->builds)->toBe([])
        ->and($builds->find('one-linux-x86_64.tar.gz'))->toBeNull()
        ->and($builds->find('two-linux-x86_64.tar.gz'))->toBeNull()
        ->and($builds->find('three-linux-x86_64.tar.gz'))->toBeNull();
});

it('finds only names the manifest lists, never a path', function () {
    $dir = distDir();
    file_put_contents($dir.'/secret.txt', 'x');
    $builds = new ClientBuilds($dir);

    foreach (['secret.txt', '../secret.txt', 'manifest.json', '', '%2e%2e%2fsecret.txt'] as $name) {
        expect($builds->find($name))->toBeNull();
    }
});

it('verifies the sha256 on request and notices a swapped file of the same size', function () {
    $dir = distDir(files: ['x-linux-x86_64.tar.gz' => 'abcd']);
    $builds = new ClientBuilds($dir);
    $build = $builds->find('x-linux-x86_64.tar.gz');

    expect($builds->verified($build))->toBeTrue();

    file_put_contents($dir.'/x-linux-x86_64.tar.gz', 'abce');

    expect($builds->verified($build))->toBeFalse();
});

it('matches the server only on the same version and, when known, commit', function () {
    expect((new ClientBuilds(distDir()))->matchesServer())->toBeTrue();

    config(['app.version' => 'v2']);
    expect((new ClientBuilds(distDir()))->matchesServer())->toBeFalse();

    config(['app.version' => 'v1', 'app.commit' => 'fffffffffff0']);
    expect((new ClientBuilds(distDir()))->matchesServer())->toBeFalse();

    config(['app.version' => 'v1', 'app.commit' => null]);
    expect((new ClientBuilds(distDir()))->matchesServer())->toBeTrue();

    expect((new ClientBuilds('/nonexistent/x'))->matchesServer())->toBeFalse();
});
