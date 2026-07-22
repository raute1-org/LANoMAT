<?php

use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

/** @param array<string, HealthResult> $results */
function preflightWith(array $results): RunPreflight
{
    $checks = array_map(
        fn (string $key, HealthResult $r): HealthCheck => new class($key, $r) implements HealthCheck
        {
            public function __construct(private string $k, private HealthResult $r) {}

            public function key(): string
            {
                return $this->k;
            }

            public function label(): string
            {
                return ucfirst($this->k);
            }

            public function run(): HealthResult
            {
                return $this->r;
            }
        },
        array_keys($results),
        array_values($results),
    );

    return new RunPreflight($checks);
}

it('exits 0 when nothing is down', function () {
    $this->app->instance(RunPreflight::class, preflightWith([
        'database' => HealthResult::ok(),
        'pelican' => HealthResult::skipped('Nicht konfiguriert.'),
    ]));

    $this->artisan('lanomat:preflight')->assertExitCode(0);
});

it('exits 1 when a check is down', function () {
    $this->app->instance(RunPreflight::class, preflightWith([
        'redis' => HealthResult::down('refused'),
    ]));

    $this->artisan('lanomat:preflight')->assertExitCode(1);
});
