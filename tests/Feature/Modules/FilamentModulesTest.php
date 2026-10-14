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

    $modulesWithResources = collect(glob(app_path('Modules/*/Filament/Resources'), GLOB_ONLYDIR))
        ->map(fn (string $dir) => basename(dirname($dir, 2)));

    expect($modulesWithResources)->not->toBeEmpty();

    foreach ($modulesWithResources as $module) {
        expect(collect($resources)->contains(fn ($c) => str_contains($c, "Modules\\{$module}\\")))
            ->toBeTrue("No Filament resource registered for module {$module} under full");
    }
});

it('keeps the legacy Filament resource registration order under the full profile', function () {
    $resources = modulesReport('full')['filament_resources'];
    $legacy = ['Events', 'Seating', 'Schedule', 'Catering', 'Teams', 'Games', 'Tournaments', 'Voting', 'Infoscreen', 'GameServers', 'Hosts', 'CustomServers', 'Files', 'Voice', 'Gallery', 'News'];

    $positions = [];
    foreach ($legacy as $module) {
        $index = collect($resources)->search(fn ($c) => str_contains($c, "Modules\\{$module}\\"));
        if ($index !== false) {
            $positions[] = $index;
        }
    }

    expect($positions)->toBe(collect($positions)->sort()->values()->all())->and(count($positions))->toBeGreaterThan(10);
});
