<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Notifications\SystemUnhealthy;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function watchCheck(string $key, HealthResult $result): HealthCheck
{
    return new class($key, $result) implements HealthCheck
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
    };
}

it('bells orga once on a healthy->unhealthy transition, not again while still down', function () {
    Cache::flush();
    Notification::fake();
    $orga = User::factory()->create(['role' => Role::Orga]);
    $this->app->instance(RunPreflight::class, new RunPreflight([
        watchCheck('redis', HealthResult::down('refused')),
    ]));

    $this->artisan('lanomat:health-watch')->assertOk();
    Notification::assertSentTo($orga, SystemUnhealthy::class);

    Notification::fake(); // reset
    $this->artisan('lanomat:health-watch')->assertOk();
    Notification::assertNothingSent(); // edge-triggered: still down, no repeat
});

it('does not bell when only skipped/ok', function () {
    Cache::flush();
    Notification::fake();
    User::factory()->create(['role' => Role::Orga]);
    $this->app->instance(RunPreflight::class, new RunPreflight([
        watchCheck('pelican', HealthResult::skipped('')),
        watchCheck('database', HealthResult::ok()),
    ]));

    $this->artisan('lanomat:health-watch')->assertOk();
    Notification::assertNothingSent();
});
