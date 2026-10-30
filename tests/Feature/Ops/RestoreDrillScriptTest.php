<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Pure parts of scripts/ops/restore-drill.sh only (syntax, option parsing and
 * the pre-checks that run before any Docker call). The real drill needs the
 * production stack and is run on the server, see docs/ops/restore-runbook.md.
 */
function drillSandbox(): array
{
    $root = sys_get_temp_dir().'/lanomat-drill-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("$root/bin");
    File::ensureDirectoryExists("$root/backups");
    File::put("$root/bin/docker", "#!/usr/bin/env bash\nexit 0\n");
    chmod("$root/bin/docker", 0755);

    return [$root, "$root/backups"];
}

function runDrill(string $root, array $args = [])
{
    return Process::path(base_path())
        ->env(['PATH' => "$root/bin:".getenv('PATH'), 'BACKUP_DIR' => "$root/backups"])
        ->run(['bash', base_path('scripts/ops/restore-drill.sh'), ...$args]);
}

it('is syntactically valid bash', function () {
    $result = Process::run(['bash', '-n', base_path('scripts/ops/restore-drill.sh')]);

    expect($result->exitCode())->toBe(0);
});

it('prints usage with --help', function () {
    [$root] = drillSandbox();

    $result = runDrill($root, ['--help']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('restore drill');

    File::deleteDirectory($root);
});

it('rejects unknown arguments with setup error 3', function () {
    [$root] = drillSandbox();

    expect(runDrill($root, ['--bogus'])->exitCode())->toBe(3);

    File::deleteDirectory($root);
});

it('fails with setup error 3 when no dump exists', function () {
    [$root] = drillSandbox();

    expect(runDrill($root)->exitCode())->toBe(3);

    File::deleteDirectory($root);
});

it('fails with setup error 3 on a corrupt dump before touching Docker', function () {
    [$root, $dir] = drillSandbox();
    File::put("$dir/db-2026-01-01_000000.sql.gz", 'not a gzip stream');

    $result = runDrill($root);

    expect($result->exitCode())->toBe(3)
        ->and($result->output())->toContain('not a valid gzip archive');

    File::deleteDirectory($root);
});
