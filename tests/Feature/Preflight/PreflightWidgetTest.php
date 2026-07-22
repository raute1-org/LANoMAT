<?php

use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Filament\Widgets\PreflightStatusWidget;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Cache;

it('caches preflight results and exposes them to the view', function () {
    Cache::flush();
    $counter = new stdClass;
    $counter->runs = 0;

    $check = new class($counter) implements HealthCheck
    {
        public function __construct(private stdClass $counter) {}

        public function key(): string
        {
            return 'database';
        }

        public function label(): string
        {
            return 'Datenbank';
        }

        public function run(): HealthResult
        {
            $this->counter->runs++;

            return HealthResult::ok();
        }
    };

    $this->app->instance(RunPreflight::class, new RunPreflight([$check]));

    $widget = new PreflightStatusWidget;

    expect($widget->results())->toHaveCount(1)
        ->and($widget->results()[0]['status'])->toBe('ok')
        ->and($widget->results())->toHaveCount(1)   // second call served from cache
        ->and($counter->runs)->toBe(1);             // proves the 15 s cache: ran once, not twice
});
