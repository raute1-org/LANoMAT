<?php

use App\Support\Modules\Module;
use Symfony\Component\Process\Process;

/**
 * @return list<Module>
 */
function requiresClosure(Module $module): array
{
    $out = [$module];
    foreach ($module->requires() as $required) {
        $out = [...$out, ...requiresClosure($required)];
    }

    return $out;
}

dataset('optional modules', fn () => collect(Module::cases())
    ->reject(fn (Module $m) => $m->isCore())
    ->mapWithKeys(fn (Module $m) => [$m->key() => [$m]])
    ->all());

it('boots with one optional module on top of core', function (Module $module) {
    $env = ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing'];
    foreach (requiresClosure($module) as $m) {
        $env['MODULE_'.strtoupper($m->key())] = 'true';
    }

    foreach ([['lanomat:modules', '--json'], ['route:list', '--json'], ['schedule:list'], ['event:list']] as $args) {
        $p = new Process([PHP_BINARY, 'artisan', ...$args], base_path(), $env);
        $p->setTimeout(120)->run();
        expect($p->isSuccessful())->toBeTrue("{$module->key()}: artisan ".implode(' ', $args).' failed: '.$p->getErrorOutput().$p->getOutput());
    }

    $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
foreach (['/', '/admin', '/events'] as $uri) {
    $status = $http->handle(Illuminate\Http\Request::create($uri))->getStatusCode();
    echo "STATUS $uri $status\n";
}
PHP;
    $probe = new Process([PHP_BINARY, '-r', $code], base_path(), $env);
    $probe->setTimeout(120)->run();
    $output = $probe->getOutput();

    expect($output)->toContain('STATUS / ', 'STATUS /admin ', 'STATUS /events ');
    // /admin/login does not exist (Discord-only auth, no Filament login page); /admin redirects guests.
    expect($output)->toMatch('#STATUS /admin (200|302)#');
    expect(preg_match('/STATUS \S+ 5\d\d/', $output))->toBe(0, "{$module->key()}: 5xx response:\n$output".$probe->getErrorOutput());
})->with('optional modules');
