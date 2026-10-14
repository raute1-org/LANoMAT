<?php

use Symfony\Component\Process\Process;

/**
 * Boot-time registration (routes, Filament, scheduler, listeners) cannot be
 * re-run inside one PHP process, so profile tests spawn `php artisan` with the
 * profile in the environment.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function modulesReport(string $profile, array $env = []): array
{
    $process = new Process(
        [PHP_BINARY, 'artisan', 'lanomat:modules', '--json'],
        base_path(),
        ['MODULES_PROFILE' => $profile, 'APP_ENV' => 'testing', ...$env],
    );
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}
