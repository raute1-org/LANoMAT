<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * The unified download page `/app`: the desktop (voice) app and the launcher in one view.
 * The page component itself is covered by the frontend build and tests/Architecture/PageLayoutsTest.
 */
beforeEach(function () {
    $this->dirs = [];
    config(['app.version' => 'v1', 'app.commit' => 'abc123def456', 'app.name' => 'LANoMAT']);

    // Both builds start unpublished; each test publishes what it needs.
    $none = sys_get_temp_dir().'/lanomat-app-none-'.bin2hex(random_bytes(4));
    config(['webvoice.native.dist_path' => $none.'-voice', 'launcher.dist_path' => $none.'-launcher']);

    $this->publishVoice = function (): void {
        $dir = sys_get_temp_dir().'/lanomat-app-voice-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $name = 'lanomat-voice-app-v1-linux-x86_64.tar.gz';
        file_put_contents($dir.'/'.$name, 'voice-bytes');
        file_put_contents($dir.'/manifest.json', json_encode([
            'app_version' => 'v1', 'commit' => 'abc123def456', 'api_version' => 1, 'built_at' => '2026-11-01T10:00:00Z',
            'files' => [['name' => $name, 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app', 'size' => 11, 'sha256' => hash('sha256', 'voice-bytes')]],
        ]));
        $this->dirs[] = $dir;
        config(['webvoice.native.dist_path' => $dir]);
    };

    $this->publishLauncher = function (): void {
        $dir = sys_get_temp_dir().'/lanomat-app-launcher-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $name = 'lanomat-launcher-v1-windows-x86_64-setup.exe';
        file_put_contents($dir.'/'.$name, 'launcher-bytes');
        file_put_contents($dir.'/manifest.json', json_encode([
            'app_version' => 'v1', 'commit' => 'abc123def456', 'built_at' => '2026-11-20T10:00:00Z',
            'files' => [['name' => $name, 'os' => 'windows', 'arch' => 'x86_64', 'kind' => 'app', 'variant' => 'installer', 'size' => 14, 'sha256' => hash('sha256', 'launcher-bytes'), 'signed' => false]],
        ]));
        $this->dirs[] = $dir;
        config(['launcher.dist_path' => $dir]);
    };
});

afterEach(function () {
    config(['launcher.dist_path' => base_path('clients-dist/launcher')]);

    foreach ($this->dirs as $dir) {
        File::deleteDirectory($dir);
    }
});

it('sends guests to the login from the page and from both old page URLs', function () {
    ($this->publishVoice)();
    ($this->publishLauncher)();

    foreach (['/app', '/launcher', '/talk/client'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
});

it('redirects the old launcher and client page URLs to /app for logged-in users', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/launcher')->assertStatus(302)->assertRedirect('/app');
    $this->get('/talk/client')->assertStatus(302)->assertRedirect('/app');
});

it('offers both downloads in one view while there are two builds', function () {
    ($this->publishVoice)();
    ($this->publishLauncher)();

    $this->actingAs(User::factory()->create())
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
        ->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->component('App/Download')
        ->has('downloads', 2)
        ->where('downloads.0.key', 'voice')
        ->where('downloads.0.state', 'current')
        ->where('downloads.0.builds.0.url', '/talk/client/download/lanomat-voice-app-v1-linux-x86_64.tar.gz')
        ->where('downloads.1.key', 'launcher')
        ->where('downloads.1.state', 'current')
        ->where('downloads.1.release.version', 'v1')
        ->where('downloads.1.builds.0.url', '/launcher/download/lanomat-launcher-v1-windows-x86_64-setup.exe')
        ->where('downloads.1.builds.0.signed', false)
        ->where('detectedOs', 'windows')
        ->where('server.url', rtrim((string) config('app.url'), '/'))
        ->where('pairUrl', '/talk/connect')
        ->where('labels.title', 'LANoMAT-App')
        ->where('labels.downloads.launcher.title', 'Spiele (Launcher)')
        ->where('labels.downloads.voice.title', 'Sprache und Chat (Desktop-App)'));
});

it('shows one card when only the launcher is published', function () {
    ($this->publishLauncher)();

    $this->actingAs(User::factory()->create())->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->has('downloads', 2)
        ->where('downloads.0.state', 'none')
        ->where('downloads.1.state', 'current'));
});

it('lists only the voice download while GameRecipes is off, and only the launcher while WebVoice is off', function () {
    ($this->publishVoice)();
    ($this->publishLauncher)();
    $user = User::factory()->create();

    $this->app->instance(Modules::class, new Modules('full', ['game_recipes' => false]));
    $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->has('downloads', 1)->where('downloads.0.key', 'voice'));

    $this->app->instance(Modules::class, new Modules('full', ['web_voice' => false]));
    $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->has('downloads', 1)->where('downloads.0.key', 'launcher')->where('pairUrl', null));
});

it('says so for every download when nothing is published, with no dead build', function () {
    $this->actingAs(User::factory()->create())->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->where('downloads.0.state', 'none')->where('downloads.0.builds', [])->where('downloads.0.release', null)
        ->where('downloads.1.state', 'none')->where('downloads.1.builds', []));
});

it('keeps the other download usable when the launcher manifest is broken', function () {
    ($this->publishVoice)();
    ($this->publishLauncher)();
    file_put_contents(config('launcher.dist_path').'/manifest.json', '{not json');

    $this->actingAs(User::factory()->create())->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->where('downloads.0.state', 'current')->where('downloads.1.state', 'none'));
});

it('flags a voice build for another server version while the launcher stays current', function () {
    ($this->publishVoice)();
    ($this->publishLauncher)();
    $manifest = config('webvoice.native.dist_path').'/manifest.json';
    file_put_contents($manifest, str_replace('"api_version":1', '"api_version":2', (string) file_get_contents($manifest)));

    $this->actingAs(User::factory()->create())->get('/app')->assertInertia(fn ($page) => $page
        ->where('downloads.0.state', 'mismatch')->where('downloads.0.release.apiVersion', 2)
        ->where('downloads.1.state', 'current'));
});

it('keeps the download routes working next to the page', function () {
    ($this->publishVoice)();
    ($this->publishLauncher)();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/launcher/download/lanomat-launcher-v1-windows-x86_64-setup.exe')->assertOk();
    $this->actingAs($user)->get('/talk/client/download/lanomat-voice-app-v1-linux-x86_64.tar.gz')->assertOk();
    $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
        ->get('/launcher/download-latest')
        ->assertRedirect('/launcher/download/lanomat-launcher-v1-windows-x86_64-setup.exe');
});
