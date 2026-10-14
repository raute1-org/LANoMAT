<?php

use App\Support\Modules\InvalidModuleConfiguration;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;

it('enables only core modules in the core profile', function () {
    $modules = new Modules('core');

    expect($modules->enabled(Module::Tournaments))->toBeTrue()
        ->and($modules->enabled(Module::Jukebox))->toBeFalse()
        ->and($modules->enabled(Module::Discord))->toBeFalse();
});

it('enables everything in the full profile', function () {
    $modules = new Modules('full');

    expect(count($modules->enabledModules()))->toBe(count(Module::cases()));
});

it('lets an override enable an optional module', function () {
    $modules = new Modules('core', ['jukebox' => true]);

    expect($modules->enabled(Module::Jukebox))->toBeTrue();
});

it('lets an override disable an optional module in the full profile', function () {
    $modules = new Modules('full', ['voice' => false]);

    expect($modules->enabled(Module::Voice))->toBeFalse();
});

it('rejects disabling a core module, an unknown profile and a missing dependency, listing every problem', function () {
    try {
        new Modules('bogus', ['events' => false, 'lancache' => true]);
        $this->fail('expected InvalidModuleConfiguration');
    } catch (InvalidModuleConfiguration $e) {
        expect($e->problems)->toContain('Unknown MODULES_PROFILE "bogus" (expected core or full).')
            ->toContain('Core module "events" cannot be disabled.')
            ->toContain('Module "lancache" requires "hosts", which is disabled.');
    }
});

it('exposes every module to route generation when asked, without enabling it', function () {
    $modules = new Modules('core', exposeAllRoutes: true);

    expect($modules->routable(Module::Jukebox))->toBeTrue()
        ->and($modules->enabled(Module::Jukebox))->toBeFalse();
});

it('keeps requires acyclic and within known modules', function () {
    foreach (Module::cases() as $module) {
        foreach ($module->requires() as $required) {
            expect($required->requires())->not->toContain($module);
        }
    }
});
