<?php

use App\Modules\Preflight\Checks\BackupFreshnessCheck;

function backupMarker(?int $ageHours, string|null $content = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'marker');
    file_put_contents($path, $content ?? (string) (now()->getTimestamp() - ($ageHours * 3600)));
    config(['preflight.backup_marker_path' => $path]);

    return $path;
}

it('is ok when the last backup is younger than 26h', function () {
    $path = backupMarker(3);

    expect(app(BackupFreshnessCheck::class)->run()->status->value)->toBe('ok');

    unlink($path);
});

it('warns between 26h and 50h', function () {
    $path = backupMarker(30);

    $result = app(BackupFreshnessCheck::class)->run();

    expect($result->status->value)->toBe('warn')->and($result->message)->toContain('30');

    unlink($path);
});

it('is down after 50h', function () {
    $path = backupMarker(51);

    expect(app(BackupFreshnessCheck::class)->run()->status->value)->toBe('down');

    unlink($path);
});

it('warns on an unreadable marker', function () {
    $path = backupMarker(null, 'garbage');

    expect(app(BackupFreshnessCheck::class)->run()->status->value)->toBe('warn');

    unlink($path);
});

it('skips a missing marker outside production', function () {
    config(['preflight.backup_marker_path' => '/nonexistent/.last-success']);

    expect(app(BackupFreshnessCheck::class)->run()->status->value)->toBe('skipped');
});

it('warns about a missing marker in production', function () {
    config(['preflight.backup_marker_path' => '/nonexistent/.last-success']);
    app()->detectEnvironment(fn () => 'production');

    expect(app(BackupFreshnessCheck::class)->run()->status->value)->toBe('warn');
});

it('is registered with the preflight runner', function () {
    $keys = collect(app()->tagged('preflight.checks'))->map(fn ($c) => $c->key());

    expect($keys)->toContain('backup');
});
