<?php

declare(strict_types=1);

namespace App\Modules\Identity\Testing;

use App\Modules\Identity\Connectors\FakeLinkedAccountConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use App\Modules\Identity\Support\LinkedAccountData;

/**
 * Single dispatcher over one {@see FakeLinkedAccountConnector} per linkable
 * provider. Returned by the `fakeLinkedAccounts()` test helper so specs can
 * write `fakeLinkedAccounts()->willResolve(LinkedAccountProvider::Steam, ...)`
 * instead of indexing into an array of fakes.
 *
 * Each per-provider fake is bound into the container under
 * {@see LinkedAccountConnectors::abstractFor()} at construction time, so
 * `app(LinkedAccountConnectors::class)->for($provider)` resolves the same
 * fake instance this dispatcher forwards to.
 */
class FakeLinkedAccounts
{
    /** @var array<string, FakeLinkedAccountConnector> */
    private array $fakes = [];

    /**
     * @param  array<int, LinkedAccountProvider>  $providers
     */
    public function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $fake = new FakeLinkedAccountConnector($provider);
            $this->fakes[$provider->value] = $fake;

            app()->instance(LinkedAccountConnectors::abstractFor($provider), $fake);
        }
    }

    public function willResolve(LinkedAccountProvider $provider, LinkedAccountData $data): void
    {
        $this->fakeFor($provider)->willResolve($data);
    }

    public function willRefresh(LinkedAccountProvider $provider, LinkedAccountData $data): void
    {
        $this->fakeFor($provider)->willRefresh($data);
    }

    public function willFailRefresh(LinkedAccountProvider $provider): void
    {
        $this->fakeFor($provider)->willFailRefresh();
    }

    public function willReportOwnership(LinkedAccountProvider $provider, bool $owns): void
    {
        $this->fakeFor($provider)->willReportOwnership($owns);
    }

    public function willReportOwnershipUnknown(LinkedAccountProvider $provider): void
    {
        $this->fakeFor($provider)->willReportOwnershipUnknown();
    }

    /**
     * Access the underlying per-provider fake directly for assertions the
     * dispatcher doesn't wrap (e.g. inspecting call history, if added later).
     */
    public function fakeFor(LinkedAccountProvider $provider): FakeLinkedAccountConnector
    {
        return $this->fakes[$provider->value];
    }
}
