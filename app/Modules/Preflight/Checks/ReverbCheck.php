<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

class ReverbCheck implements HealthCheck
{
    public function key(): string
    {
        return 'reverb';
    }

    public function label(): string
    {
        return __('preflight.checks.reverb');
    }

    public function run(): HealthResult
    {
        if (config('broadcasting.default') !== 'reverb') {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        $options = config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? '127.0.0.1';
        $port = (int) ($options['port'] ?? 8080);

        $socket = @fsockopen($host, $port, $errno, $errstr, 3.0);

        if ($socket === false) {
            return HealthResult::down("{$host}:{$port} — {$errstr}");
        }

        fclose($socket);

        return HealthResult::ok();
    }
}
