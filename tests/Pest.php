<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\Voice\Contracts\MumbleClient;
use App\Modules\Voice\Testing\FakeMumbleClient;
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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature', 'Unit/Identity', 'Unit/Discord', 'Unit/Voice', 'Unit/Tournaments/Domain');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(
        'Unit/Catering',
        'Unit/Events',
        'Unit/Registration',
        'Unit/Schedule',
        'Unit/Seating',
        'Unit/Teams',
        'Unit/Voting',
        // Persistence-layer tests only; Unit/Tournaments/Domain is pure
        // domain code (no IO, see CLAUDE.md) and stays on the plain TestCase
        // group registered above.
        'Unit/Tournaments/*Test.php',
    );

// Prevent stray HTTP requests in Discord/Voice tests to ensure all external
// communication is properly faked or declared with Http::fake.
beforeEach(function () {
    Http::preventStrayRequests();
})->in('Feature/Discord', 'Unit/Discord', 'Feature/Voice', 'Unit/Voice');

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

function fakeMumble(): FakeMumbleClient
{
    $fake = new FakeMumbleClient;
    app()->instance(MumbleClient::class, $fake);

    return $fake;
}

/**
 * Signs a Discord interaction payload the same way Discord itself does, for
 * tests that post directly to the Ed25519-verified interactions endpoint.
 *
 * @param  array<string, mixed>  $body
 * @return array{0: string, 1: string, 2: string} [json, timestamp, signature]
 */
function signedInteraction(array $body): array
{
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    Config::set('services.discord.public_key', bin2hex($public));

    $json = json_encode($body);
    $timestamp = (string) time();
    $sig = bin2hex(sodium_crypto_sign_detached($timestamp.$json, $secret));

    return [$json, $timestamp, $sig];
}
