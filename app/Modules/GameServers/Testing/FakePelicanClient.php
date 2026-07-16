<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Testing;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Models\ServerLink;
use PHPUnit\Framework\Assert;

class FakePelicanClient implements PelicanClient
{
    /** @var array<int, array{id: string, eggId: string, config: array<string, mixed>, nodeId: ?string}> */
    public array $created = [];

    /** @var array<int, array{serverId: string, action: PowerAction}> */
    public array $powerActions = [];

    /** @var array<int, string> */
    public array $deleted = [];

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

    public function createServer(string $eggId, array $config, ?string $nodeId = null): PelicanServer
    {
        if ($this->failNextCreateWith !== null) {
            $e = $this->failNextCreateWith;
            $this->failNextCreateWith = null;

            throw $e;
        }

        $id = (string) ++$this->sequence;

        $server = new PelicanServer($id, ServerState::Provisioning, null, null, $config);
        $this->servers[$id] = $server;

        $this->created[] = [
            'id' => $id,
            'eggId' => $eggId,
            'config' => $config,
            'nodeId' => $nodeId,
        ];

        return $server;
    }

    public function getServer(string $serverId): PelicanServer
    {
        return $this->servers[$serverId] ?? throw new \RuntimeException("Unknown fake Pelican server: {$serverId}");
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
            $existing->meta,
        );
    }

    public function powerAction(string $serverId, PowerAction $action): void
    {
        $this->powerActions[] = ['serverId' => $serverId, 'action' => $action];
    }

    public function deleteServer(string $serverId): void
    {
        $this->deleted[] = $serverId;
        unset($this->servers[$serverId]);
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

    public function assertPowerAction(string $serverId, PowerAction $action): void
    {
        $match = collect($this->powerActions)->contains(
            fn (array $p) => $p['serverId'] === $serverId && $p['action'] === $action
        );
        Assert::assertTrue($match, "No matching power action {$action->value} recorded for server {$serverId}.");
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
