<?php

use App\Modules\Discord\Models\DiscordOutbox;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs the callback only once per dedup key', function () {
    $guard = app(DiscordOutboxGuard::class);
    $calls = 0;

    $first = $guard->once('key-1', 'test', function () use (&$calls) {
        $calls++;
    });
    $second = $guard->once('key-1', 'test', function () use (&$calls) {
        $calls++;
    });

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($calls)->toBe(1)
        ->and(DiscordOutbox::where('dedup_key', 'key-1')->count())->toBe(1);
});

it('marks sent_at after a successful send', function () {
    app(DiscordOutboxGuard::class)->once('key-2', 'test', fn () => null);

    expect(DiscordOutbox::where('dedup_key', 'key-2')->first()->sent_at)->not->toBeNull();
});

it('lets a failed send be retried at once instead of treating it as still running', function () {
    $guard = app(DiscordOutboxGuard::class);
    $calls = 0;

    // The queue re-runs a failed job within a second. That retry must send again: the
    // leftover row belongs to an attempt that already failed, not to one still in flight.
    try {
        $guard->once('key-retry', 'test', function () use (&$calls) {
            $calls++;

            throw new RuntimeException('discord down');
        });
    } catch (RuntimeException) {
    }

    $retried = $guard->once('key-retry', 'test', function () use (&$calls) {
        $calls++;
    });

    expect($retried)->toBeTrue()
        ->and($calls)->toBe(2)
        ->and(DiscordOutbox::where('dedup_key', 'key-retry')->first()->sent_at)->not->toBeNull();
});

it('still refuses to send while another attempt is genuinely in flight', function () {
    $guard = app(DiscordOutboxGuard::class);
    $inner = null;

    $guard->once('key-flight', 'test', function () use ($guard, &$inner) {
        // A second attempt starts while the first is still inside its send.
        $inner = $guard->once('key-flight', 'test', fn () => throw new LogicException('must not send twice'));
    });

    expect($inner)->toBeFalse();
});
