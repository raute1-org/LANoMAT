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
 * @param  array<string, mixed>  $windowsEntryOverrides  merged into the windows installer entry (the zip entry when `$legacy`)
 * @param  bool  $legacy  the layout before the installer: ONE windows app zip, no `variant` field anywhere
 * @return array{string, array<string, string>} directory and key => file name
 */
function mergedDist(string $version = 'm12-9-gabc1234', array $windowsEntryOverrides = [], bool $legacy = false): array
{
    $dir = sys_get_temp_dir().'/lanomat-merged-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $files = [
        'linux-app' => ["lanomat-voice-app-$version-linux-x86_64.tar.gz", 'linux', 'app', 'tar-bytes', null, 'archive'],
        'linux-cli' => ["lanomat-voice-cli-$version-linux-x86_64.tar.gz", 'linux', 'cli', 'tar-cli-bytes', false, 'archive'],
    ];
    $files += $legacy
        ? ['win-app' => ["lanomat-voice-app-$version-windows-x86_64.zip", 'windows', 'app', 'PK-zip-bytes', false, 'archive']]
        : [
            'win-setup' => ["lanomat-voice-app-$version-windows-x86_64-setup.exe", 'windows', 'app', 'MZ-setup-bytes', false, 'installer'],
            'win-portable' => ["lanomat-voice-app-$version-windows-x86_64-portable.exe", 'windows', 'app', 'MZ-portable-bytes', false, 'portable'],
        ];
    $files['win-cli'] = ["lanomat-voice-cli-$version-windows-x86_64.exe", 'windows', 'cli', 'MZ-exe-bytes', false, 'archive'];

    $entries = [];
    $names = [];
    foreach ($files as $key => [$name, $os, $kind, $content, $signed, $variant]) {
        file_put_contents($dir.'/'.$name, $content);
        $entry = ['name' => $name, 'os' => $os, 'arch' => 'x86_64', 'kind' => $kind, 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
        if (! $legacy) {
            $entry['variant'] = $variant;
        }
        if ($signed !== null) {
            $entry['signed'] = $signed;
        }
        if ($key === ($legacy ? 'win-app' : 'win-setup')) {
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
        ->and($builds->current()->builds)->toHaveCount(5);

    $windows = $builds->forPlatform('windows', 'x86_64');
    expect(array_map(fn ($b) => $b->name, $windows))->toBe([$names['win-setup'], $names['win-portable'], $names['win-cli']])
        ->and($windows[0]->kind)->toBe('app')
        ->and($windows[0]->variant)->toBe('installer')
        ->and($windows[0]->signed)->toBeFalse()
        ->and($windows[1]->variant)->toBe('portable')
        ->and($windows[2]->kind)->toBe('cli')
        ->and($windows[2]->variant)->toBe('archive');

    foreach ($builds->current()->builds as $build) {
        expect($builds->verified($build))->toBeTrue();
    }

    // A local linux entry without the field has an unknown (null) signed state.
    expect($builds->forPlatform('linux', 'x86_64')[0]->signed)->toBeNull()
        ->and($builds->offer()['oses'])->toBe(['linux', 'windows']);
});

it('still reads a manifest from before the installer: one windows zip and no variant field', function () {
    [, $names] = mergedDist(legacy: true);
    $builds = new ClientBuilds;

    expect($builds->current())->not->toBeNull()
        ->and($builds->matchesServer())->toBeTrue()
        ->and($builds->current()->builds)->toHaveCount(4);

    $zip = $builds->find($names['win-app']);
    expect($zip->variant)->toBe('archive')
        ->and($zip->contentType())->toBe('application/zip')
        ->and($builds->verified($zip))->toBeTrue();
});

it('rejects a manifest whose signed flag is not a boolean', function () {
    mergedDist(windowsEntryOverrides: ['signed' => 'yes']);

    expect((new ClientBuilds)->current())->toBeNull();
});

it('rejects a variant that is unknown, wrong for the file or not a windows app', function (array $override) {
    mergedDist(windowsEntryOverrides: $override);

    expect((new ClientBuilds)->current())->toBeNull();
})->with([
    'unknown variant' => [['variant' => 'msi']],
    'variant not a string' => [['variant' => 7]],
    'portable on the setup file' => [['variant' => 'portable']],
    'installer on a linux entry' => [['variant' => 'installer', 'os' => 'linux']],
    'installer that is a cli' => [['variant' => 'installer', 'kind' => 'cli']],
]);

it('serves the files with their content types, attachment disposition, nosniff and the checksum', function (string $key, string $contentType, string $content) {
    [, $names] = mergedDist();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/talk/client/download/'.$names[$key]);

    $response->assertOk()
        ->assertHeader('Content-Type', $contentType)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Checksum-Sha256', hash('sha256', $content));
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Content-Disposition'))->toContain($names[$key])
        ->and($response->headers->get('Content-Type'))->not->toContain('html')
        ->and($response->streamedContent())->toBe($content);
})->with([
    'installer' => ['win-setup', 'application/vnd.microsoft.portable-executable', 'MZ-setup-bytes'],
    'portable exe' => ['win-portable', 'application/vnd.microsoft.portable-executable', 'MZ-portable-bytes'],
    'cli exe' => ['win-cli', 'application/vnd.microsoft.portable-executable', 'MZ-exe-bytes'],
    'tar.gz' => ['linux-app', 'application/gzip', 'tar-bytes'],
]);

it('serves an old windows zip with its content type', function () {
    [, $names] = mergedDist(legacy: true);

    $this->actingAs(User::factory()->create())->get('/talk/client/download/'.$names['win-app'])
        ->assertOk()->assertHeader('Content-Type', 'application/zip');
});

it('lists the windows installer and the portable exe on the download page', function () {
    mergedDist();
    $user = User::factory()->create();

    $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130')->get('/talk/client')
        ->assertOk()->assertInertia(
            fn ($page) => $page->component('WebVoice/ClientDownload')
                ->where('state', 'current')
                ->where('detectedOs', 'windows')
                ->has('builds', 5)
                ->where('builds.2.os', 'windows')
                ->where('builds.2.kind', 'app')
                ->where('builds.2.variant', 'installer')
                ->where('builds.2.signed', false)
                ->where('builds.2.url', '/talk/client/download/lanomat-voice-app-m12-9-gabc1234-windows-x86_64-setup.exe')
                ->where('builds.3.variant', 'portable')
                ->where('builds.3.url', '/talk/client/download/lanomat-voice-app-m12-9-gabc1234-windows-x86_64-portable.exe')
                ->where('builds.4.kind', 'cli')
                ->where('builds.4.variant', 'archive')
                ->where('builds.0.signed', null)
                ->where('labels.variant_installer', 'Installer (empfohlen)')
                ->where('labels.variant_portable', 'Portable EXE (ohne Installation)')
                ->where('labels.step_win_smartscreen', fn ($text) => str_contains($text, 'Weitere Informationen') && str_contains($text, 'Trotzdem ausführen'))
                ->where('labels.webview2_note', fn ($text) => str_contains($text, 'WebView2') && str_contains($text, 'Internet'))
                ->where('labels.unsigned_title', 'Diese Windows-Datei ist nicht signiert'),
        );
});

it('marks an old zip manifest as an archive variant on the download page', function () {
    mergedDist(legacy: true);

    $this->actingAs(User::factory()->create())->get('/talk/client')
        ->assertOk()->assertInertia(
            fn ($page) => $page->has('builds', 4)
                ->where('builds.2.variant', 'archive')
                ->where('builds.2.url', '/talk/client/download/lanomat-voice-app-m12-9-gabc1234-windows-x86_64.zip'),
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
        ->and($builds->forPlatform('windows', 'x86_64'))->not->toBeEmpty()
        ->and($builds->forPlatform('linux', 'x86_64'))->toHaveCount(2);

    foreach ($builds->current()->builds as $build) {
        expect($builds->verified($build))->toBeTrue();
    }
});
