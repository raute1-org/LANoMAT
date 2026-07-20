<?php

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Testing\FakeDiscordClient;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Testing\FakePelicanClient;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Testing\FakeRemoteExecutor;
use App\Modules\Identity\Connectors\FakeLinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Testing\FakeVoiceClient;
use App\Modules\Voice\VoiceProviders;
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
        'Unit/CustomServers',
        'Unit/Events',
        'Unit/Games',
        'Unit/Hosts',
        'Unit/Infoscreen',
        'Unit/Lfg',
        'Unit/Presence',
        'Unit/Registration',
        'Unit/Schedule',
        'Unit/Seating',
        'Unit/Stats',
        'Unit/Teams',
        'Unit/Voting',
        // Persistence-layer tests only; Unit/Tournaments/Domain is pure
        // domain code (no IO, see CLAUDE.md) and stays on the plain TestCase
        // group registered above.
        'Unit/Tournaments/*Test.php',
        // ServerLinkTest hits the DB (factories create rows); the Fake/Http
        // Pelican client tests are pure IO-contract tests but sharing the
        // RefreshDatabase group with ServerLinkTest is harmless (it just
        // wraps them in a transaction) and keeps the directory as one group.
        'Unit/GameServers',
    );

// Prevent stray HTTP requests in Discord/Voice tests to ensure all external
// communication is properly faked or declared with Http::fake.
beforeEach(function () {
    Http::preventStrayRequests();
})->in('Feature/Discord', 'Unit/Discord', 'Feature/Voice', 'Unit/Voice', 'Feature/GameServers', 'Unit/GameServers', 'Feature/Lfg', 'Feature/Schedule', 'Feature/Infoscreen', 'Feature/Tournaments');

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

function fakeMumble(): FakeVoiceClient
{
    return fakeVoice(['mumble'])['mumble'];
}

/**
 * Bind an in-memory fake for every active voice provider and return them keyed by value.
 *
 * @param  array<int, string>  $providers
 * @return array<string, FakeVoiceClient>
 */
function fakeVoice(array $providers = ['mumble', 'teamspeak']): array
{
    config(['services.voice.providers' => $providers]);

    $fakes = [];
    foreach ($providers as $value) {
        $fakes[$value] = new FakeVoiceClient(VoiceProvider::from($value));
    }

    if (isset($fakes['mumble'])) {
        app()->instance(VoiceClient::class, $fakes['mumble']);
    }

    app()->bind(VoiceProviders::class, fn () => new class($fakes) extends VoiceProviders
    {
        /** @param array<string, FakeVoiceClient> $fakes */
        public function __construct(private array $fakes)
        {
            parent::__construct(app());
        }

        public function active(): array
        {
            $active = [];

            foreach (VoiceProvider::active() as $provider) {
                if (isset($this->fakes[$provider->value])) {
                    $active[$provider->value] = $this->fakes[$provider->value];
                }
            }

            return $active;
        }

        public function for(VoiceProvider $provider): VoiceClient
        {
            return $this->fakes[$provider->value];
        }
    });

    return $fakes;
}

/**
 * Bind an in-memory FakeLinkedAccountConnector for every linkable provider
 * and return them keyed by provider value (mirrors fakeVoice()).
 *
 * @param  array<int, LinkedAccountProvider>  $providers
 * @return array<string, FakeLinkedAccountConnector>
 */
function fakeLinkedAccounts(array $providers = [LinkedAccountProvider::Steam, LinkedAccountProvider::Twitch]): array
{
    $fakes = [];
    foreach ($providers as $provider) {
        $fakes[$provider->value] = new FakeLinkedAccountConnector($provider);
        app()->instance(LinkedAccountConnectors::abstractFor($provider), $fakes[$provider->value]);
    }

    return $fakes;
}

function fakePelican(): FakePelicanClient
{
    $fake = new FakePelicanClient;
    app()->instance(PelicanClient::class, $fake);

    return $fake;
}

function fakeRemote(): FakeRemoteExecutor
{
    $fake = new FakeRemoteExecutor;
    app()->instance(RemoteExecutor::class, $fake);

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
