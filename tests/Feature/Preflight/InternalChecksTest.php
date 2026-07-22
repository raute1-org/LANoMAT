<?php

use App\Modules\Preflight\Checks\DatabaseCheck;
use App\Modules\Preflight\Checks\FailedJobsCheck;
use App\Modules\Preflight\Checks\StorageWritableCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reports the database as ok when reachable', function () {
    expect(app(DatabaseCheck::class)->run()->status->value)->toBe('ok');
});

it('reports storage ok when the default disk is writable', function () {
    expect(app(StorageWritableCheck::class)->run()->status->value)->toBe('ok');
});

it('warns when failed_jobs is non-empty, ok when empty', function () {
    expect(app(FailedJobsCheck::class)->run()->status->value)->toBe('ok');

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
    ]);

    expect(app(FailedJobsCheck::class)->run()->status->value)->toBe('warn');
});
