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
