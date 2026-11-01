<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\Support\ClientBuilds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{string, string} directory and file name of a published linux app build */
function publishedClient(string $version = 'v1', string $content = 'binary-bytes'): array
{
    $dir = sys_get_temp_dir().'/lanomat-dl-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $name = "lanomat-voice-app-$version-linux-x86_64.tar.gz";
    file_put_contents($dir.'/'.$name, $content);
    file_put_contents($dir.'/manifest.json', json_encode([
        'app_version' => $version,
        'commit' => 'abc123def456',
        'api_version' => 1,
        'built_at' => '2026-11-01T10:00:00Z',
        'files' => [['name' => $name, 'os' => 'linux', 'arch' => 'x86_64', 'kind' => 'app', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]],
    ]));
    test()->dists = [...test()->dists, $dir];
    config(['webvoice.native.dist_path' => $dir]);

    return [$dir, $name];
}

beforeEach(function () {
    $this->dists = [];
    config(['app.version' => 'v1', 'app.commit' => 'abc123def456']);
    $this->user = User::factory()->create();
});

afterEach(function () {
    foreach ($this->dists as $dir) {
        File::deleteDirectory($dir);
    }
});

it('sends guests to the login for the page and the file', function () {
    [, $name] = publishedClient();

    $this->get('/talk/client')->assertRedirect('/login');
    $this->get("/talk/client/download/$name")->assertRedirect('/login');
});

it('shows a logged-in user the current build with its facts', function () {
    publishedClient();

    $this->actingAs($this->user)->get('/talk/client')->assertOk()->assertInertia(
        fn ($page) => $page->component('WebVoice/ClientDownload')
            ->where('state', 'current')
            ->where('release.version', 'v1')
            ->has('builds', 1)
            ->where('builds.0.os', 'linux')
            ->where('builds.0.kind', 'app')
            ->where('builds.0.url', '/talk/client/download/lanomat-voice-app-v1-linux-x86_64.tar.gz')
            ->has('builds.0.sha256')
            ->where('server.version', 'v1')
            ->where('labels.title', 'Desktop-App herunterladen'),
    );
});

it('preselects the visitor operating system from the user agent', function (string $agent, ?string $os) {
    publishedClient();

    $this->actingAs($this->user)->withHeader('User-Agent', $agent)->get('/talk/client')
        ->assertInertia(fn ($page) => $page->where('detectedOs', $os));
})->with([
    ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'linux'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36', 'windows'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15', 'macos'],
    ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130 Mobile Safari/537.36', null],
    ['curl/8.0', null],
]);

it('warns when the build was made for another server version', function () {
    publishedClient(version: 'v0');

    $this->actingAs($this->user)->get('/talk/client')->assertInertia(
        fn ($page) => $page->where('state', 'mismatch')->where('release.version', 'v0')->where('server.version', 'v1')->has('builds', 1),
    );
});

it('says so when nothing is published', function () {
    config(['webvoice.native.dist_path' => sys_get_temp_dir().'/lanomat-none-'.bin2hex(random_bytes(4))]);

    $this->actingAs($this->user)->get('/talk/client')->assertOk()->assertInertia(
        fn ($page) => $page->where('state', 'none')->where('release', null)->has('builds', 0),
    );
});

it('streams a listed file with its checksum, name and type', function () {
    [, $name] = publishedClient(content: 'hello-client');

    $response = $this->actingAs($this->user)->get("/talk/client/download/$name");

    $response->assertOk()
        ->assertHeader('X-Checksum-Sha256', hash('sha256', 'hello-client'))
        ->assertHeader('Content-Type', 'application/gzip')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain($name)
        ->and($response->streamedContent())->toBe('hello-client');
});

it('answers 404 for anything the manifest does not list', function (string $file) {
    [$dir] = publishedClient();
    file_put_contents($dir.'/secret.txt', 'nope');

    $this->actingAs($this->user)->get('/talk/client/download/'.$file)->assertNotFound();
})->with(['secret.txt', 'manifest.json', 'unknown-linux-x86_64.tar.gz', '..%2Fsecret.txt', '%2e%2e%2f%2e%2e%2fetc%2fpasswd', '..', '.env']);

it('answers 404 when a listed file vanished or changed size', function () {
    [$dir, $name] = publishedClient();
    file_put_contents($dir.'/'.$name, 'tampered and longer');

    $this->actingAs($this->user)->get("/talk/client/download/$name")->assertNotFound();

    unlink($dir.'/'.$name);
    $this->actingAs($this->user)->get("/talk/client/download/$name")->assertNotFound();
});

it('rate limits the download', function () {
    [, $name] = publishedClient();
    $this->actingAs($this->user);

    for ($i = 0; $i < 20; $i++) {
        $this->get("/talk/client/download/$name")->assertOk();
    }

    $this->get("/talk/client/download/$name")->assertTooManyRequests();
});

it('adds the client block to the bearer config', function () {
    publishedClient();
    config(['webvoice.native.min_client_version' => 'm12-1-gaaaaaaa']);

    $response = $this->getJson('/api/voice-client/v1/config', bearerFor($this->user));

    $response->assertOk()
        ->assertHeader('X-Server-Version', 'v1')
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('client.server_version', 'v1')
        ->assertJsonPath('client.commit', 'abc123def456')
        ->assertJsonPath('client.min_client_version', 'm12-1-gaaaaaaa')
        ->assertJsonPath('client.latest_client_version', 'v1')
        ->assertJsonPath('client.download_path', '/talk/client');
});

it('reports no latest build and no floor when nothing is configured', function () {
    config(['webvoice.native.dist_path' => sys_get_temp_dir().'/lanomat-none-'.bin2hex(random_bytes(4)), 'webvoice.native.min_client_version' => null]);

    $this->getJson('/api/voice-client/v1/config', bearerFor($this->user))
        ->assertOk()
        ->assertJsonPath('client.latest_client_version', null)
        ->assertJsonPath('client.min_client_version', null)
        ->assertJsonPath('client.server_version', 'v1');
});

it('sends the server version header on errors too', function () {
    $this->getJson('/api/voice-client/v1/config')->assertUnauthorized()->assertHeader('X-Server-Version', 'v1');
});

it('falls back to dev when no version is baked in', function () {
    expect(ClientBuilds::serverVersion())->toBe('v1');
    expect(config('app.version'))->toBeString()->not->toBe('');
});

/** @return array<string, string> */
function bearerFor(User $user): array
{
    $plain = 'lvc_'.Str::random(48);
    $token = new VoiceClientToken;
    $token->forceFill([
        'user_id' => $user->id,
        'device_name' => 'Test PC',
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->addDays(90),
    ])->save();

    return ['Authorization' => 'Bearer '.$plain];
}
