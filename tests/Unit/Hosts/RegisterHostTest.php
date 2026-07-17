<?php

use App\Models\User;
use App\Modules\Hosts\Actions\RegisterHost;
use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// A real, freshly generated ed25519 OpenSSH private key (test-only, unused
// anywhere else) so RegisterHost's phpseclib-backed validation is exercised
// against an actually-parseable key rather than a marker-shaped string.
const VALID_TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACCE4M2ej63S3++lmULlRIGtsfSOztdfLdfuJhwZJs4YNAAAAIhZnz9ZWZ8/
WQAAAAtzc2gtZWQyNTUxOQAAACCE4M2ej63S3++lmULlRIGtsfSOztdfLdfuJhwZJs4YNA
AAAEB6C5J/9twky+Du+oFgSgXbwz+MC2OIf8jlM8CtNSTywITgzZ6PrdLf76WZQuVEga2x
9I7O118t1+4mHBkmzhg0AAAABHRlc3QB
-----END OPENSSH PRIVATE KEY-----
PEM;

function registerHostData(): array
{
    return [
        'name' => 'Lancache-01',
        'hostname' => '10.0.0.5',
        'ssh_port' => 22,
        'ssh_user' => 'lanomat',
        'role' => HostRole::Lancache,
        'event_id' => null,
    ];
}

it('rejects a participant (non-orga) with AuthorizationException', function () {
    $participant = User::factory()->create();

    expect(fn () => (new RegisterHost)->handle(registerHostData(), VALID_TEST_PRIVATE_KEY, $participant))
        ->toThrow(AuthorizationException::class);

    expect(RemoteHost::count())->toBe(0);
});

it('lets an orga register a host and persists the encrypted key', function () {
    $orga = User::factory()->orga()->create();

    $host = (new RegisterHost)->handle(registerHostData(), VALID_TEST_PRIVATE_KEY, $orga);

    expect($host)->toBeInstanceOf(RemoteHost::class)
        ->and($host->exists)->toBeTrue()
        ->and($host->name)->toBe('Lancache-01')
        ->and($host->ssh_private_key)->toBe(VALID_TEST_PRIVATE_KEY);

    $raw = DB::table('remote_hosts')->where('id', $host->id)->value('ssh_private_key');
    expect($raw)->not->toBe(VALID_TEST_PRIVATE_KEY);
});

it('rejects a PEM-shaped but unparseable private key', function () {
    $orga = User::factory()->orga()->create();

    $garbage = "-----BEGIN OPENSSH PRIVATE KEY-----\nnotarealkeyatall\n-----END OPENSSH PRIVATE KEY-----";

    expect(fn () => (new RegisterHost)->handle(registerHostData(), $garbage, $orga))
        ->toThrow(ValidationException::class);

    expect(RemoteHost::count())->toBe(0);
});

it('rejects an empty private key', function () {
    $orga = User::factory()->orga()->create();

    expect(fn () => (new RegisterHost)->handle(registerHostData(), '', $orga))
        ->toThrow(ValidationException::class);
});
