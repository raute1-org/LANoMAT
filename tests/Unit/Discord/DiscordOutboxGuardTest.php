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

it('does not treat a failed send as done, so a stale retry with the same dedup key actually resends', function () {
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

    // The row is still fresh (just created moments ago), so it is
    // indistinguishable from a live concurrent attempt -> must not be
    // resent yet.
    $immediateRetry = $guard->once('dedup-fails-once', 'kind', function () use (&$calls) {
        $calls++;
    });

    expect($immediateRetry)->toBeFalse()
        ->and($calls)->toBe(1);

    // Once the row is older than the in-flight lease, it can no longer be a
    // live concurrent attempt -> a genuine queue retry (e.g. the job being
    // re-run after the queue's retry_after) must actually invoke the
    // callback again, not silently no-op forever.
    DiscordOutbox::where('dedup_key', 'dedup-fails-once')->update([
        'updated_at' => now()->subSeconds(DiscordOutboxGuard::IN_FLIGHT_LEASE_SECONDS + 5),
    ]);

    $staleRetry = $guard->once('dedup-fails-once', 'kind', function () use (&$calls) {
        $calls++;
    });

    expect($staleRetry)->toBeTrue()
        ->and($calls)->toBe(2)
        ->and(DiscordOutbox::where('dedup_key', 'dedup-fails-once')->count())->toBe(1)
        ->and(DiscordOutbox::where('dedup_key', 'dedup-fails-once')->whereNotNull('sent_at')->exists())->toBeTrue();
});

it('does not resend a fresh sent_at IS NULL row, since it may be a concurrent in-flight attempt', function () {
    // Simulates the race: another attempt already inserted the row and is
    // still inside its own $send() call (sent_at not yet set). A second
    // concurrent call for the same dedup key must NOT conclude "failed,
    // resend" just because sent_at is null — the row is fresh, not stale.
    DiscordOutbox::create([
        'kind' => 'kind',
        'dedup_key' => 'dedup-in-flight',
        'sent_at' => null,
    ]);

    $guard = app(DiscordOutboxGuard::class);
    $calls = 0;

    $result = $guard->once('dedup-in-flight', 'kind', function () use (&$calls) {
        $calls++;
    });

    expect($result)->toBeFalse()
        ->and($calls)->toBe(0)
        ->and(DiscordOutbox::where('dedup_key', 'dedup-in-flight')->whereNull('sent_at')->count())->toBe(1);
});

it('resends a stale sent_at IS NULL row, since it is a genuinely abandoned attempt', function () {
    // Simulates a crashed/abandoned attempt: the row was inserted long
    // enough ago (older than the in-flight lease) that it can no longer be
    // a live concurrent send, so this call may safely take over and resend.
    $row = DiscordOutbox::create([
        'kind' => 'kind',
        'dedup_key' => 'dedup-abandoned',
        'sent_at' => null,
    ]);
    $row->forceFill([
        'created_at' => now()->subSeconds(DiscordOutboxGuard::IN_FLIGHT_LEASE_SECONDS + 5),
        'updated_at' => now()->subSeconds(DiscordOutboxGuard::IN_FLIGHT_LEASE_SECONDS + 5),
    ])->saveQuietly();

    $guard = app(DiscordOutboxGuard::class);
    $calls = 0;

    $result = $guard->once('dedup-abandoned', 'kind', function () use (&$calls) {
        $calls++;
    });

    expect($result)->toBeTrue()
        ->and($calls)->toBe(1)
        ->and(DiscordOutbox::where('dedup_key', 'dedup-abandoned')->count())->toBe(1)
        ->and(DiscordOutbox::where('dedup_key', 'dedup-abandoned')->whereNotNull('sent_at')->exists())->toBeTrue();
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
