<?php

use App\Modules\Preflight\Checks\QueueWorkerCheck;
use App\Modules\Preflight\Checks\SchedulerHeartbeatCheck;
use App\Modules\Preflight\Jobs\QueueHeartbeatJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('scheduler check is down without a marker, ok with a fresh one', function () {
    Cache::forget('preflight.scheduler_tick');
    expect(app(SchedulerHeartbeatCheck::class)->run()->status->value)->toBe('down');

    Cache::put('preflight.scheduler_tick', now(), now()->addMinutes(10));
    expect(app(SchedulerHeartbeatCheck::class)->run()->status->value)->toBe('ok');
});

it('queue check is down when the marker is stale', function () {
    Cache::put('preflight.queue_tick', now()->subMinutes(5), now()->addMinutes(10));
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
