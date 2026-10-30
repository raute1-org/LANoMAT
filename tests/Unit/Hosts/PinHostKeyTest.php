<?php

use App\Models\User;
use App\Modules\Hosts\Actions\PinHostKey;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

const FP_ONE = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const FP_TWO = 'SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

it('pins an out-of-band fingerprint and refuses to replace a different pin', function () {
    $host = RemoteHost::factory()->create(['host_fingerprint' => null]);
    $actor = User::factory()->orga()->create();

    expect(app(PinHostKey::class)->handle($host, FP_ONE, $actor)->fresh()->host_fingerprint)->toBe(FP_ONE);
    expect(app(PinHostKey::class)->handle($host, FP_ONE, $actor)->host_fingerprint)->toBe(FP_ONE);
    expect(fn () => app(PinHostKey::class)->handle($host, FP_TWO, $actor))->toThrow(RemoteExecutionException::class);
});

it('validates the fingerprint format and the actor', function () {
    $host = RemoteHost::factory()->create();

    expect(fn () => app(PinHostKey::class)->handle($host, 'MD5:abc', User::factory()->orga()->create()))->toThrow(ValidationException::class);
    expect(fn () => app(PinHostKey::class)->handle($host, FP_ONE, User::factory()->create()))->toThrow(AuthorizationException::class);
});
