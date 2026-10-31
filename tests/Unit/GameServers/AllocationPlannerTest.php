<?php

use App\Modules\GameServers\Domain\PelicanAllocation;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\AllocationPlanner;
use App\Modules\GameServers\Support\PortPool;
use App\Modules\GameServers\Testing\FakePelicanClient;

function plannerFake(array $ports, array $assigned = [], string $ip = '192.0.2.10'): FakePelicanClient
{
    $fake = new FakePelicanClient;

    foreach ($ports as $i => $port) {
        $fake->allocations[] = new PelicanAllocation((string) ($i + 1), $ip, $port, null, in_array($port, $assigned, true));
    }

    return $fake;
}

it('parses ranges, lists and single ports of the pool setting', function () {
    expect(PortPool::parse('27015-27017')->contains(27016))->toBeTrue()
        ->and(PortPool::parse('27015-27017')->contains(27018))->toBeFalse()
        ->and(PortPool::parse('27015, 27020-27021')->ports())->toBe([27015, 27020, 27021])
        ->and(PortPool::parse('')->ports())->toBe(PortPool::parse('27015-27050')->ports());
});

it('picks the lowest free allocation inside the pool and ignores ports outside it', function () {
    $fake = plannerFake([25565, 27020, 27016, 27017], assigned: [27016]);

    $picked = (new AllocationPlanner($fake, PortPool::parse('27015-27050')))->plan('1', 1);

    expect($picked)->toHaveCount(1)->and($picked[0]->port)->toBe(27017);
});

it('picks one allocation per requested port, main first', function () {
    $fake = plannerFake([27015, 27016, 27017]);

    $picked = (new AllocationPlanner($fake, PortPool::parse('27015-27050')))->plan('1', 2);

    expect(array_map(fn ($a) => $a->port, $picked))->toBe([27015, 27016]);
});

it('fails with noFreeAllocation when the pool has no free allocation', function () {
    $fake = plannerFake([25565, 27015], assigned: [27015]);

    expect(fn () => (new AllocationPlanner($fake, PortPool::parse('27015-27050')))->plan('1', 1))
        ->toThrow(GameServerException::class);
    expect($fake->createdAllocations)->toBe([]);
});

it('creates missing pool allocations when allowed and a key with that scope exists', function () {
    $fake = plannerFake([27015], assigned: [27015]);

    $picked = (new AllocationPlanner($fake, PortPool::parse('27015-27017'), createMissing: true))->plan('1', 1);

    expect($fake->createdAllocations)->toBe([['node' => '1', 'ip' => '192.0.2.10', 'ports' => ['27016']]])
        ->and($picked[0]->port)->toBe(27016);
});

it('uses the configured allocation ip over the one of existing allocations when creating', function () {
    $fake = plannerFake([27015], assigned: [27015]);

    (new AllocationPlanner($fake, PortPool::parse('27015-27017'), createMissing: true, allocationIp: '198.51.100.7'))->plan('1', 1);

    expect($fake->createdAllocations[0]['ip'])->toBe('198.51.100.7');
});

it('degrades to noFreeAllocation when the key may not create allocations', function () {
    $fake = plannerFake([27015], assigned: [27015]);
    $fake->failNextAllocationCreateWith(GameServerException::pelicanUnauthorized(403));

    expect(fn () => (new AllocationPlanner($fake, PortPool::parse('27015-27017'), createMissing: true))->plan('1', 1))
        ->toThrow(GameServerException::class, 'no free allocation');
});

it('does not create anything when the node has no allocation to copy the ip from', function () {
    $fake = new FakePelicanClient;

    expect(fn () => (new AllocationPlanner($fake, PortPool::parse('27015-27017'), createMissing: true))->plan('1', 1))
        ->toThrow(GameServerException::class);
    expect($fake->createdAllocations)->toBe([]);
});
