<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\WebVoice\Support\ClientBuilds;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

const LINUX_UA = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';
const WINDOWS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36';
const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130 Mobile Safari/537.36';
const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

/** Publishes a manifest with one build per given os ('app' kind unless $kind says otherwise). */
function publishClientFor(array $oses = ['linux'], string $version = 'v1', string $kind = 'app'): string
{
    $dir = sys_get_temp_dir().'/lanomat-entry-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $files = [];

    foreach ($oses as $os) {
        $name = "lanomat-voice-app-$version-$os-x86_64.tar.gz";
        file_put_contents($dir.'/'.$name, 'bytes');
        $files[] = ['name' => $name, 'os' => $os, 'arch' => 'x86_64', 'kind' => $kind, 'size' => 5, 'sha256' => hash('sha256', 'bytes')];
    }

    file_put_contents($dir.'/manifest.json', json_encode([
        'app_version' => $version, 'commit' => 'abc123def456', 'api_version' => 1,
        'built_at' => '2026-11-01T10:00:00Z', 'files' => $files,
    ]));
    test()->entryDirs = [...test()->entryDirs, $dir];
    config(['webvoice.native.dist_path' => $dir]);

    return $dir;
}

beforeEach(function () {
    $this->entryDirs = [];
    Cache::flush();
    config(['app.version' => 'v1', 'app.commit' => 'abc123def456']);
    $this->user = User::factory()->create();
});

afterEach(function () {
    foreach ($this->entryDirs as $dir) {
        File::deleteDirectory($dir);
    }
});

it('offers the app to a desktop visitor whose OS has a matching build', function () {
    publishClientFor(['linux', 'windows']);

    $this->actingAs($this->user)->withHeader('User-Agent', LINUX_UA)->get('/dashboard')->assertInertia(
        fn ($page) => $page->where('desktopClient', [
            'url' => '/talk/client', 'version' => 'v1', 'os' => 'linux', 'available' => true,
        ])->where('clientHint.cta', 'Desktop-App holen')
            ->where('clientHint.heading', 'Alles in einer App: Sprache, Chat, Essen, Abstimmungen, Server'),
    );
});

it('carries the offer on every page, not only the dashboard', function () {
    publishClientFor(['linux']);

    $this->actingAs($this->user)->withHeader('User-Agent', LINUX_UA)->get('/events')
        ->assertInertia(fn ($page) => $page->where('desktopClient.available', true));
});

it('does not offer a build for an OS that has none', function () {
    publishClientFor(['linux']);

    $this->actingAs($this->user)->withHeader('User-Agent', WINDOWS_UA)->get('/dashboard')->assertInertia(
        fn ($page) => $page->where('desktopClient.os', 'windows')->where('desktopClient.available', false),
    );
});

it('never offers the app on phones and tablets', function (string $agent) {
    publishClientFor(['linux', 'windows', 'macos']);

    $this->actingAs($this->user)->withHeader('User-Agent', $agent)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('desktopClient.available', false));
})->with(['android' => ANDROID_UA, 'iphone' => IPHONE_UA]);

it('does not offer a build published for another server version', function () {
    publishClientFor(['linux'], 'v0');

    $this->actingAs($this->user)->withHeader('User-Agent', LINUX_UA)->get('/dashboard')->assertInertia(
        fn ($page) => $page->where('desktopClient.available', false)->where('desktopClient.version', 'v0'),
    );
});

it('does not offer anything when nothing is published or only the CLI is', function () {
    config(['webvoice.native.dist_path' => sys_get_temp_dir().'/lanomat-entry-missing-'.bin2hex(random_bytes(4))]);

    $this->actingAs($this->user)->withHeader('User-Agent', LINUX_UA)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('desktopClient.available', false)->where('desktopClient.version', null));

    publishClientFor(['linux'], 'v1', 'cli');
    Cache::flush();

    $this->actingAs($this->user)->withHeader('User-Agent', LINUX_UA)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('desktopClient.available', false));
});

it('shares no offer with guests', function () {
    publishClientFor(['linux']);

    $this->withHeader('User-Agent', LINUX_UA)->get('/')->assertInertia(fn ($page) => $page->where('desktopClient', null));
});

it('shares no offer while the WebVoice module has no routes', function () {
    publishClientFor(['linux']);
    $this->app->instance(Modules::class, new Modules('full', ['web_voice' => false]));

    $this->actingAs($this->user)->withHeader('User-Agent', LINUX_UA)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('desktopClient', null));
});

it('caches the manifest summary for 60 seconds and re-reads when the manifest changes', function () {
    $dir = publishClientFor(['linux']);
    $manifest = $dir.'/manifest.json';
    $mtime = (int) filemtime($manifest);
    $builds = fn (): ClientBuilds => new ClientBuilds($dir);

    expect($builds()->offer()['version'])->toBe('v1');

    // Same size and mtime: the cached summary answers, the file is not parsed again.
    file_put_contents($manifest, str_replace('"v1"', '"v2"', (string) file_get_contents($manifest)));
    touch($manifest, $mtime);
    config(['app.version' => 'v1']);
    expect($builds()->offer()['version'])->toBe('v1');

    // A new mtime is a new key: the change shows up at once.
    touch($manifest, $mtime + 10);
    expect($builds()->offer()['version'])->toBe('v2');
});

it('redirects the short alias /app: guests to the login, users to the download page', function () {
    $this->get('/app')->assertRedirect('/login');

    $this->actingAs($this->user)->get('/app')->assertStatus(302)->assertRedirect('/talk/client');

    expect(route('client.download.short', absolute: false))->toBe('/app');
});
