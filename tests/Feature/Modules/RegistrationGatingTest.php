<?php

use Symfony\Component\Process\Process;

require_once __DIR__.'/ModulesProcess.php';

it('schedules only core commands under the core profile', function () {
    $scheduled = implode("\n", modulesReport('core')['scheduled']);

    expect($scheduled)->toContain('lanomat:tournament-tick')->toContain('lanomat:heartbeat');

    foreach (['lanomat:jukebox-tick', 'lanomat:sweep-discord-outbox', 'lanomat:send-schedule-reminders'] as $command) {
        expect($scheduled)->not->toContain($command);
    }
});

it('tags only core Preflight probes under the core profile', function () {
    $keys = modulesReport('core')['preflight'];

    expect($keys)->toContain('database', 'redis');

    foreach (['discord_api', 'discord_gateway', 'mumble', 'teamspeak', 'pelican', 'music_assistant'] as $key) {
        expect($keys)->not->toContain($key);
    }
});

it('keeps all probes and schedule entries under full', function () {
    $report = modulesReport('full');

    expect(implode("\n", $report['scheduled']))->toContain('lanomat:jukebox-tick');
    expect(count($report['preflight']))->toBe(13);
});

it('registers no optional listener under the core profile', function () {
    // MatchReady keeps its core listener (NotifyRosterOnMatchReady) — assert the optional ones via event:list instead.
    $list = new Process([PHP_BINARY, 'artisan', 'event:list', '--json'], base_path(), ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing']);
    $list->mustRun();

    expect($list->getOutput())->toContain('NotifyRosterOnMatchReady')
        ->not->toContain('CreateMatchChannelOnReady')
        ->not->toContain('ProvisionMatchVoiceOnReady');
});
