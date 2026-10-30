<?php

use App\Models\User;
use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Filament\Widgets\PreflightStatusWidget;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

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

// Regression (prod bug, 2026-10-30): the widget lives in the Filament admin
// panel, which does NOT load the app's Tailwind CSS. It must render status with
// Filament-native badges and German status words, not app-only utilities.
it('renders status words and filament badges for admin', function () {
    Cache::flush();

    $ok = new class implements HealthCheck
    {
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
            return HealthResult::ok('Verbindung ok');
        }
    };

    $down = new class implements HealthCheck
    {
        public function key(): string
        {
            return 'redis';
        }

        public function label(): string
        {
            return 'Redis';
        }

        public function run(): HealthResult
        {
            return HealthResult::down('Verbindung abgelehnt');
        }
    };

    $this->app->instance(RunPreflight::class, new RunPreflight([$ok, $down]));

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(PreflightStatusWidget::class)
        ->assertSee('Preflight-Ampel')
        ->assertSee('Neu prüfen')
        ->assertSee('Datenbank')
        ->assertSee('Verbindung ok')
        ->assertSee('OK')
        ->assertSee('fi-color-success')
        ->assertSee('Redis')
        ->assertSee('Verbindung abgelehnt')
        ->assertSee('Ausfall')
        ->assertSee('fi-color-danger')
        ->assertDontSee('bg-ok');
});
