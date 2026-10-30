<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const MIB = 1024 * 1024;

// Laravel's own ValidatePostSize refuses anything above the host PHP
// post_max_size (8M on a stock CLI) before our middleware runs, so the
// behaviour tests use small caps that stay below it.
beforeEach(function () {
    config(['lanomat.limits.default_mb' => 1, 'lanomat.limits.gallery_mb' => 3, 'lanomat.limits.files_mb' => 5, 'lanomat.limits.large_mb' => 7]);
});

function shippedLimits(): array
{
    return require base_path('config/lanomat.php');
}

it('refuses an oversized Content-Length on a normal route with 413', function () {
    Route::middleware('web')->post('/_test/limit', fn () => 'ok')->name('test.limit');
    $max = (int) config('lanomat.limits.default_mb') * MIB;

    $this->call('POST', '/_test/limit', [], [], [], ['CONTENT_LENGTH' => $max + 1])
        ->assertStatus(413)
        ->assertSee('Anfrage zu groß');
});

it('answers JSON clients with a German 413 message', function () {
    Route::middleware('web')->post('/_test/limit-json', fn () => 'ok');
    $max = (int) config('lanomat.limits.default_mb') * MIB;

    $this->call('POST', '/_test/limit-json', [], [], [], [
        'CONTENT_LENGTH' => $max + 1,
        'HTTP_ACCEPT' => 'application/json',
    ])->assertStatus(413)->assertJsonPath('message', 'Die Anfrage ist zu groß.');
});

it('lets a request at the default cap through', function () {
    Route::middleware('web')->post('/_test/limit-ok', fn () => 'ok');
    $max = (int) config('lanomat.limits.default_mb') * MIB;

    $this->call('POST', '/_test/limit-ok', [], [], [], ['CONTENT_LENGTH' => $max])
        ->assertOk();
});

it('applies the higher caps only to the configured upload routes', function (string $routeName, string $tier) {
    Route::middleware('web')->post('/_test/up-'.$routeName, fn () => 'ok')->name($routeName.'.test');
    config(['lanomat.limits.routes' => [$routeName.'.test' => $tier]]);
    $default = (int) config('lanomat.limits.default_mb') * MIB;
    $cap = (int) config('lanomat.limits.'.$tier) * MIB;

    $this->call('POST', '/_test/up-'.$routeName, [], [], [], ['CONTENT_LENGTH' => $default + 1])->assertOk();
    $this->call('POST', '/_test/up-'.$routeName, [], [], [], ['CONTENT_LENGTH' => $cap + 1])->assertStatus(413);
})->with([
    ['gallery', 'gallery_mb'],
    ['files', 'files_mb'],
    ['upload', 'large_mb'],
]);

it('maps the real upload routes to a higher tier than the default', function () {
    $routes = (array) config('lanomat.limits.routes');

    foreach (array_keys($routes) as $name) {
        expect(Route::has($name))->toBeTrue("route {$name} must exist");
        expect((int) config('lanomat.limits.'.$routes[$name]))->toBeGreaterThan((int) config('lanomat.limits.default_mb'));
    }
});

it('applies the default cap to a real authenticated route', function () {
    $user = User::factory()->create();
    $max = (int) config('lanomat.limits.default_mb') * MIB;

    $this->actingAs($user)
        ->call('POST', '/teams', [], [], [], ['CONTENT_LENGTH' => $max + 1])
        ->assertStatus(413);
});

it('keeps the Traefik body caps in sync with config/lanomat.php', function () {
    $dynamic = Yaml::parseFile(base_path('docker/traefik/dynamic.yml'));
    $caps = fn (string $name): int => $dynamic['http']['middlewares'][$name]['buffering']['maxRequestBodyBytes'];

    expect($caps('body-limit-default'))->toBe((int) shippedLimits()['limits']['default_mb'] * MIB)
        ->and($caps('body-limit-gallery'))->toBe((int) shippedLimits()['limits']['gallery_mb'] * MIB)
        ->and($caps('body-limit-files'))->toBe((int) shippedLimits()['limits']['files_mb'] * MIB)
        ->and($caps('body-limit-large'))->toBe((int) shippedLimits()['limits']['large_mb'] * MIB);
});

it('keeps every cap above what the validation rules legitimately accept', function () {
    $livewireMaxKb = (int) str_replace('max:', '', (string) collect(config('livewire.temporary_file_upload.rules'))->first(
        fn ($rule) => is_string($rule) && str_starts_with($rule, 'max:'),
    ));

    expect((int) shippedLimits()['limits']['files_mb'])->toBeGreaterThan((int) config('files.max_upload_mb'))
        ->and((int) shippedLimits()['limits']['gallery_mb'])->toBeGreaterThan((int) config('gallery.max_upload_mb'))
        ->and((int) shippedLimits()['limits']['large_mb'] * 1024)->toBeGreaterThan($livewireMaxKb);
});

it('routes the app through a body-limited router and leaves reverb alone', function () {
    $compose = Yaml::parseFile(base_path('compose.yml'));
    $app = $compose['services']['app']['labels'];
    $reverb = $compose['services']['reverb-prod']['labels'];

    expect($app['traefik.http.routers.app.middlewares'])->toContain('body-limit-default@file')
        ->and($app['traefik.http.routers.app-gallery.middlewares'])->toContain('body-limit-gallery@file')
        ->and($app['traefik.http.routers.app-files.middlewares'])->toContain('body-limit-files@file')
        ->and($app['traefik.http.routers.app-upload.middlewares'])->toContain('body-limit-large@file')
        ->and(array_keys($reverb))->each->not->toContain('middlewares');
});
