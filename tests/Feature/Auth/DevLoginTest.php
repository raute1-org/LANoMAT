<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// CSRF verification is normally skipped while `app()->runningUnitTests()`
// (APP_ENV === 'testing'), but these tests deliberately flip `app()['env']`
// to `production`/`local` to exercise the environment guard, which also
// re-arms CSRF for the test client. That is orthogonal to what this suite
// verifies (the local-only guard + login behaviour), so it is disabled here.
beforeEach(fn () => $this->withoutMiddleware(PreventRequestForgery::class));

it('404s when the environment is not local', function () {
    // Default test env is "testing", so the guard must already 404.
    $this->post('/dev/login/participant')->assertNotFound();
    expect(auth()->check())->toBeFalse();

    app()['env'] = 'production';
    $this->post('/dev/login/orga')->assertNotFound();
    expect(auth()->check())->toBeFalse();
});

it('logs in an idempotent demo participant under local', function () {
    app()['env'] = 'local';

    $this->post('/dev/login/participant')->assertRedirect();
    $this->assertAuthenticated();
    expect(User::where('email', 'demo-participant@lanomat.local')->count())->toBe(1);

    auth()->logout();
    $this->post('/dev/login/participant')->assertRedirect(); // no duplicate user
    expect(User::where('email', 'demo-participant@lanomat.local')->count())->toBe(1);
});

it('logs in a demo orga with the orga role under local', function () {
    app()['env'] = 'local';

    $this->post('/dev/login/orga')->assertRedirect();
    expect(auth()->user()->email)->toBe('demo-orga@lanomat.local')
        ->and(auth()->user()->role)->toBe(Role::Orga);
});

it('rejects an unknown demo role under local', function () {
    app()['env'] = 'local';
    $this->post('/dev/login/root')->assertNotFound();
});
