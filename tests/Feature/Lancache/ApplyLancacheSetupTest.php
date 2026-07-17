<?php

use App\Models\User;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Lancache\Actions\ApplyLancacheSetup;
use App\Modules\Lancache\Actions\ProbeLancache;
use App\Modules\Lancache\Exceptions\LancacheException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies the lancache setup on a role=Lancache host by running the bootstrap via the executor', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create(['role' => HostRole::Lancache]);
    $actor = User::factory()->orga()->create();

    $result = app(ApplyLancacheSetup::class)->handle($host, $actor);

    $fake->assertRan('lancache');
    $fake->assertRan('lancachenet/monolithic');
    expect($result)->toBeInstanceOf(CommandResult::class)
        ->and($result->ok())->toBeTrue();
});

it('refuses to apply the lancache setup on a non-Lancache host', function () {
    $fake = fakeRemote();
    $host = RemoteHost::factory()->create(['role' => HostRole::Generic]);
    $actor = User::factory()->orga()->create();

    expect(fn () => app(ApplyLancacheSetup::class)->handle($host, $actor))
        ->toThrow(LancacheException::class);

    $fake->assertNothingRan();
});

it('forbids a participant from applying the lancache setup', function () {
    $host = RemoteHost::factory()->create(['role' => HostRole::Lancache]);
    $actor = User::factory()->create();

    app(ApplyLancacheSetup::class)->handle($host, $actor);
})->throws(AuthorizationException::class);

it('marks the outcome as failed when the bootstrap command exits non-zero', function () {
    $fake = fakeRemote();
    $fake->queueResult('lancache', new CommandResult(1, '', 'boom'));
    $host = RemoteHost::factory()->create(['role' => HostRole::Lancache]);
    $actor = User::factory()->orga()->create();

    $result = app(ApplyLancacheSetup::class)->handle($host, $actor);

    expect($result->ok())->toBeFalse()
        ->and($result->stderr)->toBe('boom');
});

it('probes a lancache host for reachability via a health command', function () {
    $fake = fakeRemote();
    $fake->queueResult('lancache', new CommandResult(0, 'ok', ''));
    $host = RemoteHost::factory()->create(['role' => HostRole::Lancache]);
    $actor = User::factory()->orga()->create();

    $result = app(ProbeLancache::class)->handle($host, $actor);

    $fake->assertRan('lancache');
    expect($result->ok())->toBeTrue();
});

it('forbids a participant from probing lancache', function () {
    $host = RemoteHost::factory()->create(['role' => HostRole::Lancache]);
    $actor = User::factory()->create();

    app(ProbeLancache::class)->handle($host, $actor);
})->throws(AuthorizationException::class);

it('has a german translation key on LancacheException for a non-lancache host', function () {
    $host = RemoteHost::factory()->create(['role' => HostRole::Generic]);

    $exception = LancacheException::notALancacheHost($host);

    expect($exception->translationKey)->toBe('lancache.errors.not_a_lancache_host')
        ->and(__($exception->translationKey))->toBe('Dieser Host ist keine registrierte LanCache-Instanz.');
});
