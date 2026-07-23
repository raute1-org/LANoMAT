<?php

use App\Modules\Preflight\Checks\QueueWorkerCheck;
use App\Modules\Preflight\Checks\SchedulerHeartbeatCheck;
use App\Modules\Preflight\Jobs\QueueHeartbeatJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('scheduler check is down without a marker, ok with a fresh one', function () {
    Cache::forget('preflight.scheduler_tick');
    expect(app(SchedulerHeartbeatCheck::class)->run()->status->value)->toBe('down');

    Cache::put('preflight.scheduler_tick', now()->getTimestamp(), now()->addMinutes(10));
    expect(app(SchedulerHeartbeatCheck::class)->run()->status->value)->toBe('ok');
});

it('scheduler check is down on a stale marker', function () {
    Cache::put('preflight.scheduler_tick', now()->subMinutes(5)->getTimestamp(), now()->addMinutes(10));
    expect(app(SchedulerHeartbeatCheck::class)->run()->status->value)->toBe('down');
});

it('queue check is down when the marker is stale', function () {
    Cache::put('preflight.queue_tick', now()->subMinutes(5)->getTimestamp(), now()->addMinutes(10));
    expect(app(QueueWorkerCheck::class)->run()->status->value)->toBe('down');
});

it('heartbeat command writes the scheduler marker and queues the worker ping', function () {
    Queue::fake();
    Cache::forget('preflight.scheduler_tick');

    $this->artisan('lanomat:heartbeat')->assertOk();

    expect(Cache::has('preflight.scheduler_tick'))->toBeTrue();
    Queue::assertPushed(QueueHeartbeatJob::class);
});

it('the queue heartbeat job writes its marker', function () {
    Cache::forget('preflight.queue_tick');
    (new QueueHeartbeatJob)->handle();
    expect(Cache::has('preflight.queue_tick'))->toBeTrue();
});

// Regression (prod bug, 2026-07-22): the liveness markers must survive a
// *serializing* cache store. Prod runs `redis`/`file`, which push values
// through serialize()/unserialize() with config('cache.serializable_classes')
// === false — that returns any *object* as __PHP_Incomplete_Class, so a cached
// Carbon failed the checks' `instanceof CarbonInterface` guard and scheduler +
// queue always read "down" no matter how often the heartbeat ticked. Tests
// missed it because phpunit.xml pins CACHE_STORE=array (no serialization).
// Storing an int timestamp needs no class deserialization and survives every
// store, so this test pins the fix against a real serializing store.
it('liveness markers survive a serializing cache store (file)', function () {
    config(['cache.default' => 'file']);
    Cache::clearResolvedInstances();
    Cache::store('file')->flush();

    $this->artisan('lanomat:heartbeat')->assertOk(); // QUEUE_CONNECTION=sync runs the job inline

    expect(Cache::get('preflight.scheduler_tick'))->toBeInt()
        ->and(Cache::get('preflight.queue_tick'))->toBeInt()
        ->and(app(SchedulerHeartbeatCheck::class)->run()->status->value)->toBe('ok')
        ->and(app(QueueWorkerCheck::class)->run()->status->value)->toBe('ok');

    Cache::store('file')->flush();
});
