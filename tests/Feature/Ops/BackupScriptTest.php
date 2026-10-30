<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Runs scripts/ops/backup.sh against a fake `docker` shim on PATH. Pure bash
 * logic (pipefail, gzip -t, size sanity check, marker, retention) — no real
 * Docker or Postgres involved.
 */
function backupSandbox(): array
{
    $root = sys_get_temp_dir().'/lanomat-backup-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("$root/bin");
    File::ensureDirectoryExists("$root/backups");

    // `docker compose ...` = pg_dump stand-in, `docker run ...` = tar stand-in.
    // FAKE_DB: ok|fail|tiny   FAKE_STORAGE: ok|fail|tiny|corrupt
    // (tiny = a real but very small tar.gz, like a fresh volume with one
    // .gitignore; corrupt = bytes that are not a gzip stream)
    File::put("$root/bin/docker", <<<'SH'
#!/usr/bin/env bash
case "$1" in
  compose)
    case "${FAKE_DB:-ok}" in
      fail) echo "pg_dump: error" >&2; exit 1 ;;
      tiny) printf 'x' ;;
      *) head -c 4096 /dev/urandom ;;
    esac ;;
  run)
    case "${FAKE_STORAGE:-ok}" in
      fail) exit 1 ;;
      corrupt) printf 'not a gzip stream' ;;
      tiny)
        d=$(mktemp -d)
        printf '*\n!.gitignore\n' > "$d/.gitignore"
        tar czf - -C "$d" .
        rm -rf "$d" ;;
      *)
        d=$(mktemp -d)
        head -c 4096 /dev/urandom > "$d/uploads.bin"
        tar czf - -C "$d" .
        rm -rf "$d" ;;
    esac ;;
esac
SH);
    chmod("$root/bin/docker", 0755);

    return [$root, "$root/backups"];
}

function runBackup(string $root, array $env = [])
{
    return Process::path(base_path())
        ->env($env + [
            'PATH' => "$root/bin:".getenv('PATH'),
            'BACKUP_DIR' => "$root/backups",
        ])
        ->run(['bash', base_path('scripts/ops/backup.sh')]);
}

it('writes verified archives and the success marker', function () {
    [$root, $dir] = backupSandbox();

    $result = runBackup($root);

    expect($result->exitCode())->toBe(0)
        ->and(glob("$dir/db-*.sql.gz"))->toHaveCount(1)
        ->and(glob("$dir/storage-*.tgz"))->toHaveCount(1)
        ->and(trim(File::get("$dir/.last-success")))->toMatch('/^\d{10}$/')
        ->and($result->output())->toMatch('/^\d{4}-\d{2}-\d{2}T/');

    File::deleteDirectory($root);
});

it('fails without marker or leftovers when pg_dump fails (pipefail)', function () {
    [$root, $dir] = backupSandbox();

    $result = runBackup($root, ['FAKE_DB' => 'fail']);

    expect($result->exitCode())->toBe(1)
        ->and(file_exists("$dir/.last-success"))->toBeFalse()
        ->and(glob("$dir/db-*"))->toBe([])
        ->and(glob("$dir/.tmp.*"))->toBe([]);

    File::deleteDirectory($root);
});

it('rejects a suspiciously tiny dump', function () {
    [$root, $dir] = backupSandbox();

    $result = runBackup($root, ['FAKE_DB' => 'tiny']);

    expect($result->exitCode())->toBe(1)
        ->and(file_exists("$dir/.last-success"))->toBeFalse()
        ->and(glob("$dir/db-*"))->toBe([]);

    File::deleteDirectory($root);
});

it('accepts a tiny valid storage archive (fresh volume with only .gitignore)', function () {
    [$root, $dir] = backupSandbox();

    $result = runBackup($root, ['FAKE_STORAGE' => 'tiny']);

    expect($result->exitCode())->toBe(0)
        ->and(glob("$dir/storage-*.tgz"))->toHaveCount(1)
        ->and(file_exists("$dir/.last-success"))->toBeTrue();

    File::deleteDirectory($root);
});

it('rejects a corrupt storage archive with code 2 and publishes nothing', function () {
    [$root, $dir] = backupSandbox();

    $result = runBackup($root, ['FAKE_STORAGE' => 'corrupt']);

    expect($result->exitCode())->toBe(2)
        ->and(file_exists("$dir/.last-success"))->toBeFalse()
        ->and(glob("$dir/db-*"))->toBe([])
        ->and(glob("$dir/storage-*"))->toBe([]);

    File::deleteDirectory($root);
});

it('fails with code 2 when the storage archive fails and publishes nothing', function () {
    [$root, $dir] = backupSandbox();

    $result = runBackup($root, ['FAKE_STORAGE' => 'fail']);

    expect($result->exitCode())->toBe(2)
        ->and(file_exists("$dir/.last-success"))->toBeFalse()
        ->and(glob("$dir/db-*"))->toBe([]);

    File::deleteDirectory($root);
});

it('prunes backups older than the retention window', function () {
    [$root, $dir] = backupSandbox();
    touch("$dir/db-old.sql.gz", time() - 20 * 86400);
    touch("$dir/storage-old.tgz", time() - 20 * 86400);

    $result = runBackup($root);

    expect($result->exitCode())->toBe(0)
        ->and(file_exists("$dir/db-old.sql.gz"))->toBeFalse()
        ->and(file_exists("$dir/storage-old.tgz"))->toBeFalse();

    File::deleteDirectory($root);
});
