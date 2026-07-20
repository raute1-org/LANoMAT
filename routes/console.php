<?php

use App\Modules\Identity\Jobs\RefreshExpiringTokensJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lanomat:send-reminders')->everyFiveMinutes();
Schedule::command('lanomat:sweep-discord-outbox')->everyFiveMinutes();
Schedule::command('lanomat:tournament-tick')->everyMinute();
Schedule::command('lanomat:prune-lfg')->everyFiveMinutes();
Schedule::command('lanomat:send-schedule-reminders')->everyMinute();
Schedule::job(new RefreshExpiringTokensJob)->hourly();
