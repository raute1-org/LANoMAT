<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Testing;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanAllocation;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Domain\PelicanNode;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Domain\ServerLimits;
use App\Modules\GameServers\Domain\ServerSpec;
use App\Modules\GameServers\Domain\ServerStats;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Models\ServerLink;
use PHPUnit\Framework\Assert;

class FakePelicanClient implements PelicanClient
{
    /** @var array<int, array{id: string, eggId: string, spec: ServerSpec}> */
    public array $created = [];

    /** @var array<int, array{identifier: string, action: PowerAction}> */
    public array $powerActions = [];

    /** @var array<int, string> */
    public array $deleted = [];

    /** @var list<PelicanNode> */
    public array $nodes = [];

    /** @var list<PelicanAllocation> */
    public array $allocations = [];

    /** @var array<string, PelicanEgg> keyed by egg id */
    public array $eggs = [];

    /**
     * Keyed by server id. PHP coerces numeric string keys (our sequential
     * ids are "1", "2", ...) to int array keys, so the key type here is
     * `int|string` even though callers only ever pass string ids.
     *
     * @var array<int|string, PelicanServer>
     */
    private array $servers = [];

    private int $sequence = 0;

    private ?\Throwable $failNextCreateWith = null;

    /**
     * Test helper (not part of the contract) to simulate a transient Pelican
     * API failure on the next {@see createServer()} call — e.g. to assert a
     * caller's failure handling (marking a {@see ServerLink}
     * `Failed`) without a real HTTP client.
     */
    public function failNextCreateWith(\Throwable $e): void
    {
        $this->failNextCreateWith = $e;
    }

    /**
     * Test helper: a minimal valid spec.
     */
    public static function makeSpec(string $eggId = '5', ?string $nodeId = null, string $name = 'CS2 Server'): ServerSpec
    {
        return new ServerSpec($name, '1', $eggId, [], new ServerLimits(memory: 1024, disk: 2048), nodeId: $nodeId);
    }

    public function createServer(ServerSpec $spec): PelicanServer
    {
        if ($this->failNextCreateWith !== null) {
            $e = $this->failNextCreateWith;
            $this->failNextCreateWith = null;

            throw $e;
        }

        $id = (string) ++$this->sequence;

        $server = new PelicanServer(
            id: $id,
            state: ServerState::Provisioning,
            identifier: self::identifierFor($id),
            uuid: self::identifierFor($id).'-0000-4000-8000-000000000000',
            name: $spec->name,
            nodeId: $spec->nodeId,
            allocationId: $spec->allocationId,
            limits: $spec->limits,
        );
        $this->servers[$id] = $server;

        $this->created[] = ['id' => $id, 'eggId' => $spec->eggId, 'spec' => $spec];

        return $server;
    }

    public function getServer(string $serverId): PelicanServer
    {
        return $this->servers[$serverId] ?? throw new \RuntimeException("Unknown fake Pelican server: {$serverId}");
    }

    public function listServers(): array
    {
        return array_values($this->servers);
    }

    /**
     * Test helper (not part of the contract) to simulate the panel's async
     * provisioning/install pipeline moving a server between states, e.g.
     * Provisioning -> Installing -> Running.
     */
    public function setState(string $serverId, ServerState $state): void
    {
        $existing = $this->getServer($serverId);

        $this->servers[$serverId] = new PelicanServer(
            $existing->id,
            $state,
            $existing->address,
            $existing->port,
            $existing->identifier,
            $existing->uuid,
            $existing->name,
            $existing->nodeId,
            $existing->allocationId,
            $existing->limits,
            $existing->meta,
        );
    }

    /**
     * Test helper: the identifier the fake assigns to the Nth created server.
     */
    public static function identifierFor(string $serverId): string
    {
        return substr(md5("fake-{$serverId}"), 0, 8);
    }

    public function powerAction(string $identifier, PowerAction $action): void
    {
        $this->powerActions[] = ['identifier' => $identifier, 'action' => $action];
    }

    public function getResources(string $identifier): ServerStats
    {
        foreach ($this->servers as $server) {
            if ($server->identifier === $identifier) {
                return new ServerStats($server->state);
            }
        }

        throw new \RuntimeException("Unknown fake Pelican server identifier: {$identifier}");
    }

    public function listClientServerIdentifiers(): array
    {
        return array_values(array_filter(array_map(fn (PelicanServer $s): ?string => $s->identifier, $this->servers)));
    }

    public function deleteServer(string $serverId): void
    {
        $this->deleted[] = $serverId;
        unset($this->servers[$serverId]);
    }

    public function listNodes(): array
    {
        return $this->nodes;
    }

    public function listAllocations(string $nodeId): array
    {
        return $this->allocations;
    }

    public function listEggs(): array
    {
        return array_values($this->eggs);
    }

    public function getEgg(string $eggId): PelicanEgg
    {
        return $this->eggs[$eggId] ?? new PelicanEgg($eggId, 'Fake egg');
    }

    public function assertServerCreated(?string $eggId = null): void
    {
        $match = collect($this->created)->contains(
            fn (array $c) => $eggId === null || $c['eggId'] === $eggId
        );
        Assert::assertTrue($match, $eggId === null
            ? 'No server was created.'
            : "No server created with eggId {$eggId}.");
    }

    public function assertPowerAction(string $identifier, PowerAction $action): void
    {
        $match = collect($this->powerActions)->contains(
            fn (array $p) => $p['identifier'] === $identifier && $p['action'] === $action
        );
        Assert::assertTrue($match, "No matching power action {$action->value} recorded for server {$identifier}.");
    }

    public function assertServerDeleted(string $serverId): void
    {
        Assert::assertContains($serverId, $this->deleted, "Server {$serverId} was not deleted.");
    }

    public function assertNothingCreated(): void
    {
        Assert::assertEmpty($this->created, 'Expected no servers to have been created.');
    }
}
