<?php

use App\Modules\Identity\Jobs\RefreshExpiringTokensJob;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$modules = app(Modules::class);

if ($modules->enabled(Module::Discord)) {
    Schedule::command('lanomat:send-reminders')->everyFiveMinutes();
    Schedule::command('lanomat:sweep-discord-outbox')->everyFiveMinutes();
}
Schedule::command('lanomat:tournament-tick')->everyMinute();
if ($modules->enabled(Module::Lfg)) {
    Schedule::command('lanomat:prune-lfg')->everyFiveMinutes();
}
if ($modules->enabled(Module::Schedule)) {
    Schedule::command('lanomat:send-schedule-reminders')->everyMinute();
}
if ($modules->enabled(Module::Jukebox)) {
    // Polling (not a push from Music Assistant) is a deliberate v1 choice —
    // everyMinute trades a bit of now-playing staleness for not hammering MA;
    // see JukeboxTickCommand for the reconciliation logic.
    Schedule::command('lanomat:jukebox-tick')->everyMinute();
}
Schedule::command('lanomat:heartbeat')->everyMinute();
Schedule::command('lanomat:health-watch')->everyFiveMinutes();
Schedule::job(new RefreshExpiringTokensJob)->hourly();
