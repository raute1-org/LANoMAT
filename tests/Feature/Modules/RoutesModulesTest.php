<?php

require_once __DIR__.'/ModulesProcess.php';

it('registers no optional-module route under the core profile', function () {
    $routes = modulesReport('core')['routes'];

    $optionalPrefixes = ['jukebox.', 'gallery.', 'voice.', 'friends.', 'schedule.', 'catering.', 'polls.',
        'lfg.', 'servers.', 'files.', 'presence.', 'recap.', 'screen.', 'overlay.', 'discord.', 'telemetry.', 'stats.'];

    foreach ($optionalPrefixes as $prefix) {
        expect(collect($routes)->contains(fn ($n) => str_starts_with($n, $prefix)))->toBeFalse("$prefix* should be absent");
    }

    foreach (['home', 'events.show', 'teams.index', 'tournaments.show', 'events.seating', 'events.register', 'orga.checkin', 'login.discord', 'notifications.read'] as $name) {
        expect($routes)->toContain($name);
    }
});

it('registers the same routes under full as before the toggles', function () {
    $routes = modulesReport('full')['routes'];

    expect($routes)->toContain('jukebox.index', 'voice.setup', 'discord.interactions', 'telemetry.cs2', 'friends.index');
});
