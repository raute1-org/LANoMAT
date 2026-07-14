<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;
/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature', 'Unit/Identity', 'Unit/Discord');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Events', 'Unit/Registration', 'Unit/Seating');

// Prevent stray HTTP requests in Discord tests to ensure all external
// communication is properly faked or declared with Http::fake.
beforeEach(function () {
    Http::preventStrayRequests();
})->in('Feature/Discord', 'Unit/Discord');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function fakeDiscord(): FakeDiscordClient
{
    $fake = new FakeDiscordClient;
    app()->instance(DiscordClient::class, $fake);

    return $fake;
}
