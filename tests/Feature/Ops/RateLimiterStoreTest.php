<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * A Redis outage must not take login and on-site check-in down with it: the
 * rate limiters use their own store (`cache.limiter`, CACHE_LIMITER_STORE).
 */
function killRedisCache(): void
{
    config([
        'database.redis.dead' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2, 'read_timeout' => 0.2],
        'cache.stores.dead' => ['driver' => 'redis', 'connection' => 'dead', 'lock_connection' => 'dead'],
        'cache.default' => 'dead',
    ]);
    app('cache')->forgetDriver('dead');
    rebuildRateLimiter();
}

/**
 * The RateLimiter singleton binds its cache store when first resolved; rebuild
 * it (keeping the registered named limiters) so it re-reads `cache.limiter`.
 */
function rebuildRateLimiter(): void
{
    $named = (fn () => $this->limiters)->call(app(RateLimiter::class));
    app()->forgetInstance(RateLimiter::class);

    $fresh = app(RateLimiter::class);
    foreach ($named as $name => $callback) {
        $fresh->for($name, $callback);
    }
}

it('defaults the limiter store to the database, independent of the default cache store', function () {
    $config = (function () {
        putenv('CACHE_LIMITER_STORE');
        unset($_ENV['CACHE_LIMITER_STORE'], $_SERVER['CACHE_LIMITER_STORE']);

        return require config_path('cache.php');
    })();

    expect($config['limiter'])->toBe('database');
});

it('lets POST /login answer normally while the default cache store is unreachable', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass-123')]);
    killRedisCache();

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(302);
});

it('lets GET /checkin/{token} answer while the default cache store is unreachable', function () {
    $user = User::factory()->create();
    Event::factory()->live()->create();
    killRedisCache();

    $this->actingAs($user)->get('/checkin/does-not-exist')->assertNotFound();
});
