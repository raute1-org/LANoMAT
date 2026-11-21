<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherBuilds;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Support\Modules\InvalidModuleConfiguration;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/**
 * Publish a launcher dist directory (files + manifest.json) and point the page at it.
 *
 * @param  list<array{name: string, os: string, arch: string, kind: string, variant?: string, content?: string, signed?: bool|null}>  $files
 * @param  array<string, mixed>  $extra  extra/overriding top-level manifest keys
 */
function launcherDist(array $files, array $extra = []): string
{
    $dir = sys_get_temp_dir().'/lanomat-launcher-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $entries = [];

    foreach ($files as $file) {
        $content = $file['content'] ?? 'launcher-binary-'.$file['name'];
        file_put_contents($dir.'/'.$file['name'], $content);
        $entries[] = [
            'name' => $file['name'],
            'os' => $file['os'],
            'arch' => $file['arch'],
            'kind' => $file['kind'],
            'variant' => $file['variant'] ?? 'archive',
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'signed' => $file['signed'] ?? null,
        ];
    }

    file_put_contents($dir.'/manifest.json', json_encode([
        'app_version' => 'v1',
        'commit' => 'abc123def456',
        'built_at' => '2026-11-20T10:00:00Z',
        'files' => $entries,
        ...$extra,
    ]));

    test()->launcherDirs = [...test()->launcherDirs, $dir];
    Config::set('launcher.dist_path', $dir);

    return $dir;
}

/** The usual release: a Linux tarball plus a Windows installer and its portable exe. */
function launcherRelease(): string
{
    return launcherDist([
        ['name' => 'lanomat-launcher-v1-linux-x86_64.tar.gz', 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app'],
        ['name' => 'lanomat-launcher-v1-windows-x86_64-setup.exe', 'os' => 'windows', 'arch' => 'x86_64', 'kind' => 'app', 'variant' => 'installer', 'signed' => false],
        ['name' => 'lanomat-launcher-v1-windows-x86_64-portable.exe', 'os' => 'windows', 'arch' => 'x86_64', 'kind' => 'app', 'variant' => 'portable', 'signed' => false],
    ]);
}

beforeEach(function () {
    // The page component itself is covered by the frontend build (npm run build) and by
    // tests/Architecture/PageLayoutsTest; these tests only assert the HTTP surface, so the
    // baked Vite manifest of the dev image must not decide whether they pass.
    $this->withoutVite();
    $this->launcherDirs = [];
    Config::set('app.version', 'v1');
    Config::set('app.name', 'LANoMAT');

    // The launcher sits behind the Discord login; guests are covered by a dedicated test.
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    // A test may point the launcher at a temp dist; never leak that into the
    // next test file (GamesPageTest runs later in the same process).
    Config::set('launcher.dist_path', base_path('clients-dist/launcher'));

    foreach ($this->launcherDirs as $dir) {
        File::deleteDirectory($dir);
    }
});

it('sends guests to the login for the page and the download', function () {
    // beforeEach signed a user in; the guest branch needs the session cleared.
    auth()->logout();

    $this->get('/launcher')->assertRedirect(route('login'));
    $this->get('/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz')
        ->assertRedirect(route('login'));
});

it('shows the published builds, one primary download per OS', function () {
    launcherRelease();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0')
        ->get('/launcher')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Launcher/Index')
            ->where('state', 'published')
            ->where('release.version', 'v1')
            ->where('release.commit', 'abc123def456')
            ->has('builds', 2)
            ->where('builds.0.os', 'linux')
            ->where('builds.0.kind', 'app')
            ->where('builds.0.url', '/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz')
            ->has('builds.0.sha256')
            ->has('builds.0.size')
            ->where('builds.1.os', 'windows')
            ->where('builds.1.variant', 'installer')
            ->has('files', 3)
            ->where('server.name', 'LANoMAT')
            // The address the request was made on — what the launcher has to be pointed at.
            ->where('server.url', rtrim((string) config('app.url'), '/'))
            ->where('server.version', 'v1')
            ->where('detectedOs', 'linux'));
});

it('sends the shell download button straight to the build for the visitor', function () {
    launcherRelease();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0')
        ->get('/launcher/download-latest')
        ->assertRedirect('/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz');

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
        ->get('/launcher/download-latest')
        ->assertRedirect('/launcher/download/lanomat-launcher-v1-windows-x86_64-setup.exe');

    // A known system without a build falls back to the page that names the gap.
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)')
        ->get('/launcher/download-latest')
        ->assertRedirect('/launcher');

    // Without a published build the page explains the empty state, never a 404.
    Config::set('launcher.dist_path', sys_get_temp_dir().'/lanomat-launcher-none-'.bin2hex(random_bytes(4)));

    $this->get('/launcher/download-latest')->assertRedirect('/launcher');
});

it('lists every live server with the one-click action that joins it', function () {
    launcherRelease();

    // UT2004 has a published launcher recipe: the row links into the launcher
    // with the server address, so the install wizard and the launch happen in
    // one flow. The browser recipe keeps its own https entry point.
    GameRecipe::factory()->published()->create([
        'slug' => 'unreal-tournament-2004',
        'title' => 'Unreal Tournament 2004',
    ]);

    $ut = Game::factory()->create([
        'slug' => 'unreal-tournament-2004',
        'name' => 'Unreal Tournament 2004',
    ]);

    $event = Event::factory()->live()->create(['name' => 'Raute1 LAN']);

    ServerLink::factory()->create([
        'event_id' => $event->id,
        'game_id' => $ut->id,
        'title' => 'Raute1 DM',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(address: '203.0.113.9', port: 7777),
        'query_info' => [
            'name' => 'Raute1 DM',
            'map' => 'DM-CORRUPTION',
            'players' => 4,
            'max_players' => 16,
        ],
        'queried_at' => now(),
    ]);

    $browser = Game::factory()->create([
        'slug' => 'need-for-speed-underground-2-browser',
        'name' => 'Need for Speed: Underground 2 (Browser)',
    ]);

    ServerLink::factory()->permanent()->create([
        'game_id' => $browser->id,
        'title' => 'NFSU2 (Browser)',
        'status' => ServerLinkStatus::Ready,
        'join_info' => new JoinInfo(
            address: 'nfsu2.xyz',
            port: 443,
            connectString: 'https://nfsu2.xyz/',
        ),
    ]);

    $this->get('/launcher')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Launcher/Index')
        ->has('servers', 2)
        // The projection sorts by game, so the browser recipe leads here.
        ->where('servers.0.title', 'NFSU2 (Browser)')
        ->where('servers.0.join.launcher', false)
        ->where('servers.0.join.href', 'https://nfsu2.xyz/')
        ->where('servers.1.title', 'Raute1 DM')
        ->where('servers.1.game', 'Unreal Tournament 2004')
        ->where('servers.1.gameSlug', 'unreal-tournament-2004')
        ->where('servers.1.players', 4)
        ->where('servers.1.maxPlayers', 16)
        ->where('servers.1.map', 'DM-CORRUPTION')
        ->where('servers.1.address', '203.0.113.9:7777')
        ->where('servers.1.event.name', 'Raute1 LAN')
        ->where('servers.1.join.launcher', true)
        ->where('servers.1.join.href', 'lanomat://join/unreal-tournament-2004?address='.rawurlencode('203.0.113.9:7777')));
});

it('keeps the launcher start page honest without a running server', function () {
    launcherRelease();

    $this->get('/launcher')->assertOk()->assertInertia(fn ($page) => $page
        ->has('servers', 0));
});

it('prefers the app over a CLI build and the installer over portable and archive', function () {
    launcherDist([
        ['name' => 'lanomat-launcher-v1-windows-x86_64-portable.exe', 'os' => 'windows', 'arch' => 'x86_64', 'kind' => 'app', 'variant' => 'portable'],
        ['name' => 'lanomat-launcher-v1-windows-x86_64-cli.zip', 'os' => 'windows', 'arch' => 'x86_64', 'kind' => 'cli'],
        ['name' => 'lanomat-launcher-v1-windows-x86_64-setup.exe', 'os' => 'windows', 'arch' => 'x86_64', 'kind' => 'app', 'variant' => 'installer'],
    ]);

    $this->get('/launcher')->assertInertia(fn ($page) => $page
        ->has('builds', 1)
        ->where('builds.0.variant', 'installer')
        ->has('files', 3));
});

it('says so when nothing is published', function () {
    Config::set('launcher.dist_path', sys_get_temp_dir().'/lanomat-launcher-none-'.bin2hex(random_bytes(4)));

    $this->get('/launcher')->assertOk()->assertInertia(fn ($page) => $page
        ->where('state', 'none')
        ->where('release', null)
        ->has('builds', 0)
        ->has('files', 0));
});

it('treats a malformed or unsafe manifest as nothing published', function (string $case) {
    $dir = launcherDist([
        ['name' => 'lanomat-launcher-v1-linux-x86_64.tar.gz', 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app'],
    ]);

    $manifest = (string) file_get_contents($dir.'/manifest.json');
    $broken = match ($case) {
        'json' => '{not json',
        'traversal' => str_replace('"name":"lanomat-launcher-v1-linux-x86_64.tar.gz"', '"name":"..\/evil.tar.gz"', $manifest),
        'no-version' => str_replace('"app_version":"v1",', '', $manifest),
        'bad-sha' => preg_replace('/"sha256":"[a-f0-9]{64}"/', '"sha256":"abc"', $manifest) ?? '',
        'unknown-os' => str_replace('"os":"linux"', '"os":"amiga"', $manifest),
        'manifest-entry' => str_replace('"name":"lanomat-launcher-v1-linux-x86_64.tar.gz"', '"name":"manifest.json"', $manifest),
        default => $manifest,
    };

    file_put_contents($dir.'/manifest.json', (string) $broken);

    $this->get('/launcher')->assertOk()->assertInertia(fn ($page) => $page
        ->where('state', 'none')
        ->has('builds', 0));
})->with(['json', 'traversal', 'no-version', 'bad-sha', 'unknown-os', 'manifest-entry']);

it('streams a listed file with its checksum, name and type', function () {
    launcherDist([
        ['name' => 'lanomat-launcher-v1-linux-x86_64.tar.gz', 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app', 'content' => 'hello-launcher'],
    ]);

    $response = $this->get('/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz');

    $response->assertOk()
        ->assertHeader('X-Checksum-Sha256', hash('sha256', 'hello-launcher'))
        ->assertHeader('Content-Type', 'application/gzip')
        ->assertHeader('Content-Length', (string) strlen('hello-launcher'))
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Served from the verified handle, not from a path that could be swapped meanwhile.
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('lanomat-launcher-v1-linux-x86_64.tar.gz')
        ->and($response->streamedContent())->toBe('hello-launcher');
});

it('refuses a file whose bytes no longer match the manifest at the same size', function () {
    $dir = launcherDist([
        ['name' => 'lanomat-launcher-v1-linux-x86_64.tar.gz', 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app', 'content' => 'hello-launcher'],
    ]);

    // Exactly the listed length, so the size check alone would let this through.
    file_put_contents($dir.'/lanomat-launcher-v1-linux-x86_64.tar.gz', 'HELLO-LAUNCHER');

    expect(filesize($dir.'/lanomat-launcher-v1-linux-x86_64.tar.gz'))->toBe(strlen('hello-launcher'));

    $this->get('/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz')->assertNotFound();
});

it('refuses a build that was swapped for a symlink', function () {
    $dir = launcherDist([
        ['name' => 'lanomat-launcher-v1-linux-x86_64.tar.gz', 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app', 'content' => 'hello-launcher'],
    ]);

    $name = 'lanomat-launcher-v1-linux-x86_64.tar.gz';

    file_put_contents($dir.'/replacement.bin', 'HELLO-LAUNCHER');
    unlink($dir.'/'.$name);
    symlink($dir.'/replacement.bin', $dir.'/'.$name);

    $this->get('/launcher/download/'.$name)->assertNotFound();
});

it('answers 404 for anything the manifest does not list or a traversal attempt', function (string $file) {
    $dir = launcherRelease();
    file_put_contents($dir.'/secret.txt', 'nope');

    $this->get('/launcher/download/'.$file)->assertNotFound();
})->with([
    'secret.txt',
    'manifest.json',
    'unknown-linux-x86_64.tar.gz',
    '..%2Fsecret.txt',
    '%2e%2e%2f%2e%2e%2fetc%2fpasswd',
    '..',
    '.env',
    'SHA256SUMS',
]);

it('answers 404 when a listed file vanished or changed size', function () {
    $dir = launcherRelease();
    $name = 'lanomat-launcher-v1-linux-x86_64.tar.gz';

    file_put_contents($dir.'/'.$name, 'tampered and much longer');

    $this->get('/launcher/download/'.$name)->assertNotFound();

    unlink($dir.'/'.$name);
    $this->get('/launcher/download/'.$name)->assertNotFound();
});

it('rate limits the download', function () {
    launcherDist([
        ['name' => 'lanomat-launcher-v1-linux-x86_64.tar.gz', 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app'],
    ]);

    for ($i = 0; $i < 20; $i++) {
        $this->get('/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz')->assertOk();
    }

    $this->get('/launcher/download/lanomat-launcher-v1-linux-x86_64.tar.gz')->assertTooManyRequests();
});

it('reads the manifest through a configured directory and never throws', function () {
    $builds = new LauncherBuilds(sys_get_temp_dir().'/lanomat-launcher-none-'.bin2hex(random_bytes(4)));

    expect($builds->manifest())->toBeNull()
        ->and($builds->builds())->toBe([])
        ->and($builds->files())->toBe([])
        ->and($builds->find('anything.tar.gz'))->toBeNull();
});

it('takes the dist directory from config, with a constructor argument winning', function () {
    expect(config('launcher.dist_path'))->toBe(base_path('clients-dist/launcher'));

    $dir = launcherRelease();

    expect((new LauncherBuilds)->basePath())->toBe($dir)
        // The Preflight check passes a directory explicitly; it must win over the config.
        ->and((new LauncherBuilds($dir.'/'))->basePath())->toBe($dir);

    Config::set('launcher.dist_path', $dir.'/nested/');

    expect((new LauncherBuilds)->basePath())->toBe($dir.'/nested');
});

it('requires Mcp, so the page never advertises a launcher that cannot pair', function () {
    expect(Module::GameRecipes->requires())->toContain(Module::Files, Module::Mcp);

    // With Mcp off, /launcher still renders, but api/mcp/device would 404 — the module
    // model turns that silent half-feature into an explicit boot error instead.
    expect(fn () => new Modules('full', ['mcp' => false]))
        ->toThrow(InvalidModuleConfiguration::class, 'Module "game_recipes" requires "mcp", which is disabled.');
});

it('serves the page with its whole requires closure on top of core', function () {
    launcherRelease();

    $modules = new Modules('core', [
        Module::GameRecipes->key() => true,
        Module::Files->key() => true,
        Module::Mcp->key() => true,
    ]);

    expect($modules->enabled(Module::GameRecipes))->toBeTrue()
        ->and($modules->enabled(Module::Files))->toBeTrue()
        ->and($modules->enabled(Module::Mcp))->toBeTrue();

    $this->get('/launcher')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Launcher/Index')
        ->where('state', 'published'));
});

it('is unreachable when the GameRecipes module is off', function () {
    // Route registration happens at boot, so the core profile has to be probed in a
    // separate process (same pattern as tests/Feature/Modules/IsolationMatrixTest).
    $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$status = $http->handle(Illuminate\Http\Request::create('/launcher'))->getStatusCode();
echo "STATUS $status\n";
PHP;

    $probe = new Process([PHP_BINARY, '-r', $code], base_path(), [
        'APP_ENV' => 'testing',
        'MODULES_PROFILE' => 'core',
    ]);
    $probe->setTimeout(120)->run();

    expect($probe->isSuccessful())->toBeTrue($probe->getErrorOutput())
        ->and($probe->getOutput())->toContain('STATUS 404');
});

it('is reachable: layout entry, sidebar entry and both homepage tiles exist', function () {
    expect((string) file_get_contents(base_path('resources/js/lib/pageLayouts.ts')))
        ->toContain("'Launcher/':");

    expect((string) file_get_contents(base_path('resources/js/components/AppSidebar.vue')))
        ->toContain("href: '/launcher'");

    // The download is the one shell action above everything: prominent in the
    // top-right of every authenticated page, not only in the sidebar.
    expect((string) file_get_contents(base_path('resources/js/components/AppSidebarHeader.vue')))
        ->toContain('data-test="header-launcher"')
        ->toContain('href="/launcher/download-latest"')
        ->toContain("has('game_recipes')");

    expect((string) file_get_contents(base_path('resources/js/pages/Event/Show.vue')))
        ->toContain('href="/launcher"');

    expect((string) file_get_contents(base_path('resources/js/pages/Event/Index.vue')))
        ->toContain('href="/launcher"');
});
