<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * Security-only assertions about the launcher page. The HTTP surface
 * itself (list, checksum, 404s, rate limit, Preflight wiring) lives in
 * LauncherPageTest; this file adds the boundaries that test does not cover.
 *
 * The last test is skipped: it is the reproduction of an open finding (see the
 * L9 report) and passes once the fix is in.
 */
function launcherSecurityDir(): string
{
    $dir = sys_get_temp_dir().'/lanomat-launcher-sec-'.bin2hex(random_bytes(6));
    mkdir($dir);

    test()->launcherSecurityDirs = [...test()->launcherSecurityDirs, $dir];
    Config::set('launcher.dist_path', $dir);

    return $dir;
}

/**
 * Write a manifest listing exactly `$names` (with a real file per name) and
 * return the directory.
 *
 * @param  list<string>  $names
 */
function launcherSecurityManifest(array $names, ?string $fileName = null): string
{
    $dir = launcherSecurityDir();
    $entries = [];

    foreach ($names as $name) {
        $actual = $fileName ?? $name;
        file_put_contents($dir.'/'.$actual, 'launcher-binary');

        $entries[] = [
            'name' => $actual,
            'os' => 'linux',
            'arch' => 'x86_64',
            'kind' => 'app',
            'variant' => 'archive',
            'size' => strlen('launcher-binary'),
            'sha256' => hash('sha256', 'launcher-binary'),
            'signed' => null,
        ];
    }

    file_put_contents($dir.'/manifest.json', json_encode([
        'app_version' => 'v1',
        'commit' => 'abc123def456',
        'built_at' => '2026-11-20T10:00:00Z',
        'files' => $entries,
    ]));

    return $dir;
}

beforeEach(function () {
    $this->withoutVite();
    $this->launcherSecurityDirs = [];
    Config::set('app.version', 'v1');
    Config::set('app.name', 'LANoMAT');

    // The inspected launcher routes require the Discord login.
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    foreach ($this->launcherSecurityDirs as $dir) {
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }
});

it('treats a manifest name that could break an HTTP header as nothing published', function (string $name) {
    // The name ends up in Content-Disposition and X-Checksum-Sha256-less routes.
    // Anything outside [A-Za-z0-9][A-Za-z0-9._-] is "not a build", so a quote,
    // a CR/LF pair or a space can never reach a response header.
    launcherSecurityManifest([$name]);

    $this->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->where('downloads', fn ($downloads) => collect($downloads)->firstWhere('key', 'launcher')['state'] === 'none'));

    $this->get('/launcher/download/'.rawurlencode($name))->assertNotFound();
})->with([
    'quote' => 'lanomat-launcher-v1.tar.gz"',
    'crlf' => "lanomat-launcher-v1.tar.gz\r\nX-Injected: 1",
    'space' => 'lanomat launcher v1.tar.gz',
    'semicolon' => 'lanomat-launcher-v1.tar.gz;filename=evil',
]);

it('offers a listed name only when it is a regular file and matches byte for byte', function () {
    // A directory with the listed name: same size is impossible to guarantee,
    // and is_file() alone must already refuse it.
    $dir = launcherSecurityManifest(['lanomat-launcher-v1-linux-x86_64.tar.gz']);

    unlink($dir.'/lanomat-launcher-v1-linux-x86_64.tar.gz');
    mkdir($dir.'/lanomat-launcher-v1-linux-x86_64.tar.gz');

    $this->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->where('downloads', fn ($downloads) => collect($downloads)->firstWhere('key', 'launcher')['state'] === 'none'));

    $this->get('/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz')->assertNotFound();
});

it('matches a requested name exactly, never case-insensitively', function () {
    launcherSecurityManifest(['lanomat-launcher-v1-linux-x86_64.tar.gz']);

    $this->get('/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz')->assertOk();
    $this->get('/launcher/download/LANOMAT-LAUNCHER-V1-LINUX-X86_64.TAR.GZ')->assertNotFound();
});

it('does not take the pairing address from an untrusted Host header', function () {
    Config::set('app.url', 'http://lanomat.test');
    launcherSecurityManifest(['lanomat-launcher-v1-linux-x86_64.tar.gz']);

    $this->get('http://evil.example/app')->assertOk()->assertInertia(fn ($page) => $page
        ->where('server.url', 'http://lanomat.test'));
});
