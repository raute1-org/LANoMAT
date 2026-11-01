<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\WebVoice\Support\ClientBuilds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * A dist directory shaped like the output of scripts/ops/fetch-client-release.sh: a merged
 * manifest with the local Linux build (no `signed` field, or signed:false) plus the workflow's
 * Windows files (signed:false).
 *
 * @param  array<string, mixed>  $windowsEntryOverrides  merged into the windows app entry
 * @return array{string, array<string, string>} directory and kind => file name
 */
function mergedDist(string $version = 'm12-9-gabc1234', array $windowsEntryOverrides = []): array
{
    $dir = sys_get_temp_dir().'/lanomat-merged-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $files = [
        'linux-app' => ["lanomat-voice-app-$version-linux-x86_64.tar.gz", 'linux', 'app', 'tar-bytes', null],
        'linux-cli' => ["lanomat-voice-cli-$version-linux-x86_64.tar.gz", 'linux', 'cli', 'tar-cli-bytes', false],
        'win-app' => ["lanomat-voice-app-$version-windows-x86_64.zip", 'windows', 'app', 'PK-zip-bytes', false],
        'win-cli' => ["lanomat-voice-cli-$version-windows-x86_64.exe", 'windows', 'cli', 'MZ-exe-bytes', false],
    ];

    $entries = [];
    $names = [];
    foreach ($files as $key => [$name, $os, $kind, $content, $signed]) {
        file_put_contents($dir.'/'.$name, $content);
        $entry = ['name' => $name, 'os' => $os, 'arch' => 'x86_64', 'kind' => $kind, 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
        if ($signed !== null) {
            $entry['signed'] = $signed;
        }
        if ($key === 'win-app') {
            $entry = array_merge($entry, $windowsEntryOverrides);
        }
        $entries[] = $entry;
        $names[$key] = $name;
    }

    file_put_contents($dir.'/manifest.json', json_encode([
        'app_version' => $version,
        'commit' => 'abc123def456',
        'api_version' => 1,
        'built_at' => '2026-11-01T10:00:00Z',
        'files' => $entries,
    ]));

    test()->mergedDirs = [...test()->mergedDirs, $dir];
    config(['webvoice.native.dist_path' => $dir]);

    return [$dir, $names];
}

beforeEach(function () {
    $this->mergedDirs = [];
    config(['app.version' => 'm12-9-gabc1234', 'app.commit' => 'abc123def456']);
});

afterEach(function () {
    foreach ($this->mergedDirs as $dir) {
        File::deleteDirectory($dir);
    }
});

it('verifies a merged linux plus windows manifest and offers both platforms', function () {
    [, $names] = mergedDist();
    $builds = new ClientBuilds;

    expect($builds->current())->not->toBeNull()
        ->and($builds->matchesServer())->toBeTrue()
        ->and($builds->current()->builds)->toHaveCount(4);

    $windows = $builds->forPlatform('windows', 'x86_64');
    expect(array_map(fn ($b) => $b->name, $windows))->toBe([$names['win-app'], $names['win-cli']])
        ->and($windows[0]->kind)->toBe('app')
        ->and($windows[0]->signed)->toBeFalse()
        ->and($windows[1]->kind)->toBe('cli');

    foreach ($builds->current()->builds as $build) {
        expect($builds->verified($build))->toBeTrue();
    }

    // A local linux entry without the field has an unknown (null) signed state.
    expect($builds->forPlatform('linux', 'x86_64')[0]->signed)->toBeNull()
        ->and($builds->offer()['oses'])->toBe(['linux', 'windows']);
});

it('rejects a manifest whose signed flag is not a boolean', function () {
    mergedDist(windowsEntryOverrides: ['signed' => 'yes']);

    expect((new ClientBuilds)->current())->toBeNull();
});

it('serves the windows files with their content types, nosniff and the checksum', function (string $key, string $contentType, string $content) {
    [, $names] = mergedDist();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/talk/client/download/'.$names[$key]);

    $response->assertOk()
        ->assertHeader('Content-Type', $contentType)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Checksum-Sha256', hash('sha256', $content));
    expect($response->streamedContent())->toBe($content);
})->with([
    'zip' => ['win-app', 'application/zip', 'PK-zip-bytes'],
    'exe' => ['win-cli', 'application/vnd.microsoft.portable-executable', 'MZ-exe-bytes'],
    'tar.gz' => ['linux-app', 'application/gzip', 'tar-bytes'],
]);

it('marks the windows entries and the signed flag on the download page', function () {
    mergedDist();
    $user = User::factory()->create();

    $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130')->get('/talk/client')
        ->assertOk()->assertInertia(
            fn ($page) => $page->component('WebVoice/ClientDownload')
                ->where('state', 'current')
                ->where('detectedOs', 'windows')
                ->has('builds', 4)
                ->where('builds.2.os', 'windows')
                ->where('builds.2.kind', 'app')
                ->where('builds.2.signed', false)
                ->where('builds.2.url', '/talk/client/download/lanomat-voice-app-m12-9-gabc1234-windows-x86_64.zip')
                ->where('builds.3.os', 'windows')
                ->where('builds.3.signed', false)
                ->where('builds.0.signed', null)
                ->where('labels.step_win_smartscreen', fn ($text) => str_contains($text, 'Weitere Informationen') && str_contains($text, 'Trotzdem ausführen'))
                ->where('labels.unsigned_title', 'Diese Windows-Datei ist nicht signiert'),
        );
});

it('validates a real downloaded manifest when LANOMAT_REAL_DIST points at one', function () {
    $dir = getenv('LANOMAT_REAL_DIST');
    if (! is_string($dir) || $dir === '' || ! is_file($dir.'/manifest.json')) {
        $this->markTestSkipped('LANOMAT_REAL_DIST is not set');
    }

    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
    config(['app.version' => $manifest['app_version'], 'app.commit' => $manifest['commit'], 'webvoice.native.dist_path' => $dir]);
    $builds = new ClientBuilds;

    expect($builds->matchesServer())->toBeTrue()
        ->and($builds->forPlatform('windows', 'x86_64'))->toHaveCount(2)
        ->and($builds->forPlatform('linux', 'x86_64'))->toHaveCount(2);

    foreach ($builds->current()->builds as $build) {
        expect($builds->verified($build))->toBeTrue();
    }
});
