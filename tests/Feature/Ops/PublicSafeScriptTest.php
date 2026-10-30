<?php

use Illuminate\Support\Facades\Process;

/**
 * scripts/ops/check-public-safe.sh guards the PUBLIC repository against
 * internal IPs, e-mail addresses, SSH key material and Discord IDs in docs,
 * scripts, ops and compose files. Pure bash, no network.
 */
function publicSafeScript(): string
{
    return base_path('scripts/ops/check-public-safe.sh');
}

it('passes its own positive and negative self-test', function () {
    $result = Process::path(base_path())->run(['bash', publicSafeScript(), '--self-test']);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    expect($result->output())->toContain('self-test passed');
});

it('finds nothing sensitive in the real repository tree', function () {
    $result = Process::path(base_path())->run(['bash', publicSafeScript()]);

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
});

it('fails with file:line and a redacted excerpt on a planted private IP', function () {
    $root = sys_get_temp_dir().'/lanomat-public-safe-'.bin2hex(random_bytes(4));
    mkdir("$root/docs", 0777, true);
    $ip = implode('.', [10, 99, 88, 77]);
    file_put_contents("$root/docs/leak.md", "ok\nserver at $ip\n");

    $result = Process::path(base_path())->run(['bash', publicSafeScript(), '--root', $root, '--allowlist', '/dev/null']);

    expect($result->exitCode())->toBe(1);
    expect($result->output())->toContain('docs/leak.md:2: [private-ip]')
        ->not->toContain($ip);

    unlink("$root/docs/leak.md");
    rmdir("$root/docs");
    rmdir($root);
});
