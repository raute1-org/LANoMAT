<?php

use Illuminate\Support\Facades\File;

/**
 * Runs docker/file-host/sync-certs.py against throw-away directories. Needs python3 and openssl,
 * which every developer machine here has; the test is skipped where they are missing.
 */
function certSyncSandbox(): array
{
    $root = sys_get_temp_dir().'/certsync-'.bin2hex(random_bytes(4));
    File::makeDirectory("{$root}/source", 0755, true);
    File::makeDirectory("{$root}/dest", 0755, true);

    return [$root, "{$root}/source", "{$root}/dest"];
}

function makeCertificatePair(string $directory, string $name, string $certName = 'certificate.crt', string $keyName = 'privatekey.key'): void
{
    $cmd = sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj %s -keyout %s -out %s 2>&1',
        escapeshellarg("/CN={$name}"),
        escapeshellarg("{$directory}/{$keyName}"),
        escapeshellarg("{$directory}/{$certName}"),
    );
    exec($cmd, $out, $code);
    expect($code)->toBe(0, implode("\n", $out));
}

function runCertSync(string $source, string $dest): string
{
    $script = base_path('docker/file-host/sync-certs.py');
    $code = sprintf(
        'import importlib.util,sys;s=importlib.util.spec_from_file_location("m",%s);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);'
        .'calls=[];print(m.sync_once(%s,%s,lambda:calls.append(1)),len(calls))',
        var_export($script, true),
        var_export($source, true),
        var_export($dest, true),
    );
    exec('python3 -I -B -c '.escapeshellarg($code).' 2>&1', $out);

    return trim(implode("\n", $out));
}

beforeEach(function () {
    exec('command -v python3 >/dev/null && command -v openssl >/dev/null', $o, $code);
    if ($code !== 0) {
        $this->markTestSkipped('python3 and openssl are needed');
    }
});

it('copies a new pair, reloads nginx once and leaves an unchanged pair alone', function () {
    [$root, $source, $dest] = certSyncSandbox();
    makeCertificatePair($source, 'files.test');

    expect(runCertSync($source, $dest))->toBe('updated 1');
    expect(File::get("{$dest}/fullchain.pem"))->toBe(File::get("{$source}/certificate.crt"));
    expect(File::get("{$dest}/privkey.pem"))->toBe(File::get("{$source}/privatekey.key"));
    expect(substr(sprintf('%o', fileperms("{$dest}/privkey.pem")), -3))->toBe('600');

    expect(runCertSync($source, $dest))->toBe('unchanged 0');

    File::deleteDirectory($root);
});

it('picks up a renewed pair', function () {
    [$root, $source, $dest] = certSyncSandbox();
    makeCertificatePair($source, 'files.test');
    runCertSync($source, $dest);
    $before = File::get("{$dest}/fullchain.pem");

    makeCertificatePair($source, 'files.test');

    expect(runCertSync($source, $dest))->toBe('updated 1');
    expect(File::get("{$dest}/fullchain.pem"))->not->toBe($before)->toBe(File::get("{$source}/certificate.crt"));

    File::deleteDirectory($root);
});

it('keeps the working copy when the new certificate does not match its key', function () {
    [$root, $source, $dest] = certSyncSandbox();
    makeCertificatePair($source, 'files.test');
    runCertSync($source, $dest);
    $good = File::get("{$dest}/fullchain.pem");
    $goodKey = File::get("{$dest}/privkey.pem");

    // A renewal half way through: the new certificate, the old key of another pair.
    makeCertificatePair($root, 'other', 'other.crt', 'other.key');
    File::copy("{$root}/other.crt", "{$source}/certificate.crt");

    expect(runCertSync($source, $dest))->toBe('invalid 0');
    expect(File::get("{$dest}/fullchain.pem"))->toBe($good);
    expect(File::get("{$dest}/privkey.pem"))->toBe($goodKey);
    expect(array_values(array_diff(scandir($dest), ['.', '..'])))->toBe(['fullchain.pem', 'privkey.pem']); // no staged files left behind

    File::deleteDirectory($root);
});

it('waits while the source has no certificate yet', function () {
    [$root, $source, $dest] = certSyncSandbox();

    expect(runCertSync($source, $dest))->toBe('missing 0');

    File::deleteDirectory($root);
});
