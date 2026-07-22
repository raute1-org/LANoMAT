<?php

use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;

function fakeCheck(string $key, callable $run): HealthCheck
{
    return new class($key, $run) implements HealthCheck
    {
        public function __construct(private string $k, private $run) {}

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
            return ($this->run)();
        }
    };
}

it('collects results from all checks', function () {
    $run = new RunPreflight([
        fakeCheck('a', fn () => HealthResult::ok('fine')),
        fakeCheck('b', fn () => HealthResult::skipped('n/a')),
    ]);

    expect($run->handle())->toBe([
        ['key' => 'a', 'label' => 'A', 'status' => 'ok', 'message' => 'fine'],
        ['key' => 'b', 'label' => 'B', 'status' => 'skipped', 'message' => 'n/a'],
    ]);
});

it('reports a throwing check as down instead of propagating', function () {
    $run = new RunPreflight([
        fakeCheck('boom', fn () => throw new RuntimeException('kaputt')),
    ]);

    $result = $run->handle();
    expect($result[0]['status'])->toBe('down')
        ->and($result[0]['message'])->toBe('kaputt');
});
