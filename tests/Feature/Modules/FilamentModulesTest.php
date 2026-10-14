<?php

require_once __DIR__.'/ModulesProcess.php';

it('registers only core module resources under the core profile', function () {
    $resources = modulesReport('core')['filament_resources'];

    expect(collect($resources)->contains(fn ($c) => str_contains($c, 'Modules\\Teams\\')))->toBeTrue()
        ->and(collect($resources)->contains(fn ($c) => str_contains($c, 'Modules\\Voice\\')))->toBeFalse()
        ->and(collect($resources)->contains(fn ($c) => str_contains($c, 'Modules\\Hosts\\')))->toBeFalse();
});

it('registers every module resource under the full profile', function () {
    $resources = modulesReport('full')['filament_resources'];

    expect(collect($resources)->contains(fn ($c) => str_contains($c, 'Modules\\Voice\\')))->toBeTrue();
});
