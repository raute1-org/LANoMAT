<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StorageWritableCheck implements HealthCheck
{
    public function key(): string
    {
        return 'storage';
    }

    public function label(): string
    {
        return __('preflight.checks.storage');
    }

    public function run(): HealthResult
    {
        $probe = 'preflight/.write-'.uniqid();

        try {
            Storage::put($probe, 'ok');
            $ok = Storage::get($probe) === 'ok';
            Storage::delete($probe);

            return $ok ? HealthResult::ok() : HealthResult::down(__('preflight.messages.storage_mismatch'));
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
