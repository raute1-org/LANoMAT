<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Contracts\LinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Container\Container;

/**
 * Registry resolving a {@see LinkedAccountConnector} per {@see LinkedAccountProvider}.
 * Bound as a singleton in AppServiceProvider; tests swap it wholesale for a
 * Fake-backed instance via the `fakeLinkedAccounts()` helper (mirrors
 * {@see VoiceProviders}).
 *
 * Unlike VoiceProviders (which `match`es a fixed, always-implemented set of
 * providers), each concrete connector here is resolved from the container
 * under a per-provider abstract (`LinkedAccountConnector::class.'@'.$value`)
 * so real connectors can be added provider-by-provider (Steam in 9.3, Twitch
 * in 9.4) without touching this registry.
 */
class LinkedAccountConnectors
{
    public function __construct(private readonly Container $app) {}

    public function for(LinkedAccountProvider $provider): LinkedAccountConnector
    {
        $abstract = self::abstractFor($provider);

        if (! $this->app->bound($abstract)) {
            throw IdentityException::unknownLinkedAccountProvider($provider);
        }

        /** @var LinkedAccountConnector */
        return $this->app->make($abstract);
    }

    /**
     * The container abstract a concrete connector is bound under for the
     * given provider, e.g. `LinkedAccountConnector@steam`.
     */
    public static function abstractFor(LinkedAccountProvider $provider): string
    {
        return LinkedAccountConnector::class.'@'.$provider->value;
    }

    /**
     * Linkable providers whose credentials are fully configured — i.e. the
     * ones actually safe to offer in the linking UI.
     *
     * @return array<int, LinkedAccountProvider>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            LinkedAccountProvider::linkable(),
            fn (LinkedAccountProvider $provider): bool => $this->isConfigured($provider),
        ));
    }

    private function isConfigured(LinkedAccountProvider $provider): bool
    {
        /** @var array<string, mixed> $config */
        $config = config("services.{$provider->socialiteDriver()}", []);

        $requiredKeys = match ($provider) {
            LinkedAccountProvider::Twitch => ['client_id', 'client_secret'],
            default => ['client_secret'],
        };

        foreach ($requiredKeys as $key) {
            if (($config[$key] ?? null) === null) {
                return false;
            }
        }

        return true;
    }
}
