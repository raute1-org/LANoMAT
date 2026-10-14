<?php

use Symfony\Component\Process\Process;

it('runs the Preflight command under core with only core probes', function () {
    $process = new Process([PHP_BINARY, 'artisan', 'lanomat:preflight'], base_path(), ['MODULES_PROFILE' => 'core', 'APP_ENV' => 'testing']);
    $process->setTimeout(120)->run();
    $output = $process->getOutput();

    // The exit code reflects the environment (Redis/scheduler may legitimately be DOWN), so only
    // assert that the command ran to completion (0 or 1) and which probes it listed.
    expect($process->getExitCode())->toBeIn([0, 1])
        ->and($output)->toContain('Datenbank', 'Redis', 'Dateispeicher', 'Fehlgeschlagene Jobs', 'Reverb', 'Scheduler', 'Queue-Worker')
        ->not->toContain('Discord')->not->toContain('Mumble')->not->toContain('TeamSpeak')
        ->not->toContain('Pelican')->not->toContain('Music Assistant');
});
