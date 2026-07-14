<?php

use App\Modules\Discord\Models\DiscordOutbox;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('fires the callback exactly once for a given dedup key', function () {
    $guard = app(DiscordOutboxGuard::class);
    $calls = 0;

    $first = $guard->once('dedup-1', 'kind', function () use (&$calls) {
        $calls++;
    });
    $second = $guard->once('dedup-1', 'kind', function () use (&$calls) {
        $calls++;
    });

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($calls)->toBe(1)
        ->and(DiscordOutbox::where('dedup_key', 'dedup-1')->whereNotNull('sent_at')->exists())->toBeTrue();
});

it('does not treat a failed send as done, so a retry with the same dedup key actually resends', function () {
    $guard = app(DiscordOutboxGuard::class);
    $calls = 0;

    expect(function () use ($guard, &$calls) {
        $guard->once('dedup-fails-once', 'kind', function () use (&$calls) {
            $calls++;
            throw new RuntimeException('Discord is down');
        });
    })->toThrow(RuntimeException::class);

    // The failed attempt's row survives (sweep support), but must not be
    // marked sent — a row's mere existence must not make every future retry
    // silently return false, permanently losing the send.
    expect(DiscordOutbox::where('dedup_key', 'dedup-fails-once')->whereNull('sent_at')->exists())->toBeTrue();

    // A genuine queue retry (a fresh call with the same dedup key) must
    // actually invoke the callback again, not silently no-op.
    $second = $guard->once('dedup-fails-once', 'kind', function () use (&$calls) {
        $calls++;
    });

    expect($second)->toBeTrue()
        ->and($calls)->toBe(2)
        ->and(DiscordOutbox::where('dedup_key', 'dedup-fails-once')->count())->toBe(1)
        ->and(DiscordOutbox::where('dedup_key', 'dedup-fails-once')->whereNotNull('sent_at')->exists())->toBeTrue();
});

it('does not resend once a dedup row has genuinely been marked sent', function () {
    $guard = app(DiscordOutboxGuard::class);
    $calls = 0;

    $guard->once('dedup-sent', 'kind', function () use (&$calls) {
        $calls++;
    });

    $second = $guard->once('dedup-sent', 'kind', function () use (&$calls) {
        $calls++;
    });

    expect($second)->toBeFalse()
        ->and($calls)->toBe(1);
});

it('rethrows a QueryException that is not a unique-key violation', function () {
    $guard = app(DiscordOutboxGuard::class);

    // Build a real QueryException carrying an arbitrary non-23505 SQLSTATE
    // (PDOException::$code is not final, unlike Exception::getCode()), so we
    // can assert the guard does not swallow it as if it were a harmless
    // dedup race.
    $pdoException = new PDOException('simulated connection error');
    $pdoException->errorInfo = ['55000', 1, 'simulated connection error'];
    (new ReflectionProperty($pdoException, 'code'))->setValue($pdoException, '55000');

    $queryException = new QueryException(
        'pgsql',
        'insert into "discord_outbox" ("kind", "dedup_key") values (?, ?)',
        [],
        $pdoException,
    );

    DB::shouldReceive('transaction')->once()->andThrow($queryException);

    expect(fn () => $guard->once('dedup-2', 'kind', fn () => null))
        ->toThrow(QueryException::class);
});
