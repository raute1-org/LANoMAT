<?php

declare(strict_types=1);

use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Support\NullLightingClient;
use App\Modules\Lighting\Support\SafeLightingClient;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Symfony\Component\Process\Process;

it('is on under full, off under core, no requirements', function () {
    expect((new Modules('full'))->enabled(Module::Lighting))->toBeTrue()
        ->and((new Modules('core'))->enabled(Module::Lighting))->toBeFalse()
        ->and((new Modules('full', ['lighting' => false]))->enabled(Module::Lighting))->toBeFalse()
        ->and(Module::Lighting->requires())->toBe([])
        ->and(Module::Lighting->isCore())->toBeFalse();
});

it('binds a null transport when not configured and wraps it in the safety client', function () {
    config(['services.lighting.enabled' => false, 'services.lighting.base_url' => null]);

    expect(app('lighting.transport'))->toBeInstanceOf(NullLightingClient::class)
        ->and(app(LightingClient::class))->toBeInstanceOf(SafeLightingClient::class)
        ->and(app(LightingClient::class)->blackout()->reachable)->toBeFalse();
});

it('exposes the service config', function () {
    expect(config('services.lighting'))->toHaveKeys(['enabled', 'base_url', 'token', 'timeout'])
        ->and(config('services.lighting.enabled'))->toBeFalse()
        ->and(config('lighting.allow_strobe'))->toBeFalse()
        ->and(config('lighting.max_strobe'))->toBe(120);
});

it('registers no page, gate, listener or preflight check when the module is off', function () {
    $env = ['MODULES_PROFILE' => 'full', 'MODULE_LIGHTING' => 'false', 'APP_ENV' => 'testing'];
    $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'GATE '.(Illuminate\Support\Facades\Gate::has('lighting.control') ? 'yes' : 'no')."\n";
echo 'LISTEN '.(Illuminate\Support\Facades\Event::hasListeners(App\Modules\Tournaments\Events\MatchReady::class) ? 'x' : '')."\n";
$tags = array_map(fn ($c) => $c::class, iterator_to_array($app->tagged('preflight.checks')));
echo 'CHECK '.(in_array(App\Modules\Preflight\Checks\LightBridgeCheck::class, $tags, true) ? 'yes' : 'no')."\n";
$panel = Filament\Facades\Filament::getPanel('admin');
echo 'PAGE '.(in_array(App\Modules\Lighting\Filament\Pages\LightControl::class, $panel->getPages(), true) ? 'yes' : 'no')."\n";
PHP;
    $p = new Process([PHP_BINARY, '-r', $code], base_path(), $env);
    $p->setTimeout(120)->run();
    $out = $p->getOutput();

    expect($out)->toContain('GATE no', 'CHECK no', 'PAGE no');
});

it('registers the lighting listeners under full and none when the module is off', function () {
    $list = function (array $env): string {
        $p = new Process([PHP_BINARY, 'artisan', 'event:list', '--json'], base_path(), ['MODULES_PROFILE' => 'full', 'APP_ENV' => 'testing', ...$env]);
        $p->setTimeout(120)->mustRun();

        return $p->getOutput();
    };

    expect($list([]))->toContain('TriggerLightingRules');
    expect($list(['MODULE_LIGHTING' => 'false']))->not->toContain('TriggerLightingRules');
});

it('refuses the console commands when the module is off', function () {
    $env = ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing'];
    $p = new Process([PHP_BINARY, 'artisan', 'lighting:status'], base_path(), $env);
    $p->setTimeout(120)->run();

    expect($p->getExitCode())->toBe(1)->and($p->getOutput().$p->getErrorOutput())->toContain('ausgeschaltet');
});
