<?php

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
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
if ($modules->enabled(Module::GameServers)) {
    Schedule::command('gameservers:refresh-queries')->everyMinute();
    // Permanent servers (any source) are probed by their own command, never by the match/event one.
    Schedule::command('gameservers:refresh-permanent')->everyMinute()->withoutOverlapping();
}
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
if ($modules->enabled(Module::Files)) {
    // Releases the quota and deletes leftovers of resumable uploads nobody finished.
    Schedule::command('lanomat:sweep-file-uploads')->everyTenMinutes()->withoutOverlapping();
}
if ($modules->enabled(Module::WebVoice)) {
    Schedule::command('webvoice:prune')->daily();
}
if ($modules->enabled(Module::Presence)) {
    // "Who is where" (voice rooms, games) for the participants: one fan-out to the voice sidecars,
    // LiveKit and Steam, cached for the requests. Only while an event is live.
    Schedule::command('presence:refresh-locations')
        ->everyThirtySeconds()
        ->withoutOverlapping(1)
        ->when(fn (): bool => Event::query()->where('status', EventStatus::Live->value)->exists());
}
Schedule::command('lanomat:heartbeat')->everyMinute();
Schedule::command('lanomat:health-watch')->everyFiveMinutes();
// More often than the 12 h cache lifetime, so the preflight check never finds an empty cache.
Schedule::command('lanomat:check-updates')->everySixHours();
Schedule::job(new RefreshExpiringTokensJob)->hourly();
Schedule::command('lanomat:refresh-avatars')->dailyAt('04:30')->withoutOverlapping()->runInBackground();
