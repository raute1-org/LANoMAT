<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanAllocation;
use App\Modules\GameServers\Exceptions\GameServerException;
use Illuminate\Support\Facades\Log;

/**
 * Chooses the node allocations a new server gets: free ones whose port lies in
 * the configured {@see PortPool}, lowest port first, one per requested port (the
 * main port plus one for each `extra_ports` entry of the preset).
 *
 * When the pool has too few free allocations and `createMissing` is on, the
 * missing pool ports are created through the Application API (needs a key with
 * write scope on allocations; a plain key gets 403 and the planner then simply
 * reports `noFreeAllocation`, creation is never required).
 */
final class AllocationPlanner
{
    public function __construct(
        private readonly PelicanClient $client,
        private readonly PortPool $pool,
        private readonly bool $createMissing = false,
        private readonly ?string $allocationIp = null,
    ) {}

    public static function fromConfig(PelicanClient $client): self
    {
        $ip = config('services.pelican.allocation_ip');

        return new self(
            $client,
            PortPool::fromConfig(),
            (bool) config('services.pelican.create_allocations', false),
            is_string($ip) && $ip !== '' ? $ip : null,
        );
    }

    /**
     * @param  list<int>  $excludePorts  Ports never handed out from the pool (fixed ports of the same server).
     * @return list<PelicanAllocation>
     *
     * @throws GameServerException {@see GameServerException::noFreeAllocation()}
     */
    public function plan(string $nodeId, int $count = 1, array $excludePorts = []): array
    {
        $all = $this->client->listAllocations($nodeId);
        $free = $this->freeInPool($all, $excludePorts);

        if (count($free) < $count && $this->createMissing) {
            $this->createMissing($nodeId, $all, $count - count($free));
            $all = $this->client->listAllocations($nodeId);
            $free = $this->freeInPool($all, $excludePorts);
        }

        if (count($free) < $count) {
            throw GameServerException::noFreeAllocation($nodeId);
        }

        return array_slice($free, 0, $count);
    }

    /**
     * The allocations for one server config, in slot order (main port, then the extra variables
     * of {@see PortSlots::variables()}). A slot with a fixed port gets exactly that port: it is
     * created on the node when missing and refused when another server already holds it. The
     * remaining slots come from the pool, never from a fixed port.
     *
     * @param  array<string, mixed>  $config  `ServerConfig::toArray()` shape.
     * @return list<PelicanAllocation>
     *
     * @throws GameServerException
     */
    public function planFor(string $nodeId, array $config): array
    {
        $slots = [PortSlots::MAIN, ...PortSlots::variables($config)];
        $fixed = PortSlots::fixed($config);

        if ($fixed === []) {
            return $this->plan($nodeId, count($slots));
        }

        $fixedAllocations = $this->fixedAllocations($nodeId, array_values($fixed));
        $poolSlots = array_values(array_filter($slots, static fn (string $slot): bool => ! isset($fixed[$slot])));
        $pool = $poolSlots === [] ? [] : $this->plan($nodeId, count($poolSlots), array_values($fixed));
        $planned = [];

        foreach ($slots as $slot) {
            $planned[] = isset($fixed[$slot]) ? $fixedAllocations[$fixed[$slot]] : (array_shift($pool) ?? throw GameServerException::noFreeAllocation($nodeId));
        }

        return $planned;
    }

    /**
     * @param  list<int>  $ports
     * @return array<int, PelicanAllocation> port => free allocation
     *
     * @throws GameServerException
     */
    private function fixedAllocations(string $nodeId, array $ports): array
    {
        $all = $this->client->listAllocations($nodeId);
        $missing = array_values(array_filter($ports, fn (int $port): bool => $this->withPort($all, $port) === []));

        if ($missing !== []) {
            $this->createFixed($nodeId, $all, $missing);
            $all = $this->client->listAllocations($nodeId);
        }

        $resolved = [];

        foreach ($ports as $port) {
            $candidates = $this->withPort($all, $port);

            if ($candidates === []) {
                throw GameServerException::fixedPortUnavailable($port);
            }

            $free = array_values(array_filter($candidates, static fn (PelicanAllocation $a): bool => ! $a->assigned));

            if ($free === []) {
                throw GameServerException::fixedPortInUse($port);
            }

            $resolved[$port] = $free[0];
        }

        return $resolved;
    }

    /**
     * @param  list<PelicanAllocation>  $all
     * @return list<PelicanAllocation>
     */
    private function withPort(array $all, int $port): array
    {
        return array_values(array_filter(
            $all,
            fn (PelicanAllocation $a): bool => $a->port === $port && ($this->allocationIp === null || $a->ip === $this->allocationIp),
        ));
    }

    /**
     * @param  list<PelicanAllocation>  $all
     * @param  list<int>  $ports
     */
    private function createFixed(string $nodeId, array $all, array $ports): void
    {
        $ip = $this->allocationIp ?? ($all[0]->ip ?? null);

        if ($ip === null || $ip === '') {
            throw GameServerException::fixedPortUnavailable($ports[0]);
        }

        try {
            $this->client->createAllocations($nodeId, $ip, array_map('strval', $ports));
        } catch (GameServerException $e) {
            Log::notice('Could not create fixed-port allocations on the Pelican node.', ['node' => $nodeId, 'ports' => $ports, 'status' => $e->httpStatus]);

            throw GameServerException::fixedPortUnavailable($ports[0]);
        }
    }

    /**
     * @param  list<PelicanAllocation>  $all
     * @param  list<int>  $excludePorts
     * @return list<PelicanAllocation>
     */
    private function freeInPool(array $all, array $excludePorts = []): array
    {
        $free = array_values(array_filter(
            $all,
            fn (PelicanAllocation $a): bool => ! $a->assigned && $this->pool->contains($a->port) && ! in_array($a->port, $excludePorts, true),
        ));

        usort($free, static fn (PelicanAllocation $a, PelicanAllocation $b): int => $a->port <=> $b->port);

        return $free;
    }

    /**
     * @param  list<PelicanAllocation>  $all
     */
    private function createMissing(string $nodeId, array $all, int $needed): void
    {
        $ip = $this->allocationIp ?? ($all[0]->ip ?? null);

        if ($ip === null || $ip === '') {
            return;
        }

        $existing = array_map(static fn (PelicanAllocation $a): int => $a->port, array_filter($all, static fn (PelicanAllocation $a): bool => $a->ip === $ip));
        $ports = array_slice(array_values(array_diff($this->pool->ports(), $existing)), 0, $needed);

        if ($ports === []) {
            return;
        }

        try {
            $this->client->createAllocations($nodeId, $ip, array_map('strval', $ports));
        } catch (GameServerException $e) {
            // A key without the scope is the normal case: fall through to noFreeAllocation.
            Log::notice('Could not create pool allocations on the Pelican node.', ['node' => $nodeId, 'status' => $e->httpStatus]);
        }
    }
}
