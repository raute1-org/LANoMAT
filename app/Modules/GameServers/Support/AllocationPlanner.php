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
     * @return list<PelicanAllocation>
     *
     * @throws GameServerException {@see GameServerException::noFreeAllocation()}
     */
    public function plan(string $nodeId, int $count = 1): array
    {
        $all = $this->client->listAllocations($nodeId);
        $free = $this->freeInPool($all);

        if (count($free) < $count && $this->createMissing) {
            $this->createMissing($nodeId, $all, $count - count($free));
            $all = $this->client->listAllocations($nodeId);
            $free = $this->freeInPool($all);
        }

        if (count($free) < $count) {
            throw GameServerException::noFreeAllocation($nodeId);
        }

        return array_slice($free, 0, $count);
    }

    /**
     * @param  list<PelicanAllocation>  $all
     * @return list<PelicanAllocation>
     */
    private function freeInPool(array $all): array
    {
        $free = array_values(array_filter(
            $all,
            fn (PelicanAllocation $a): bool => ! $a->assigned && $this->pool->contains($a->port),
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
