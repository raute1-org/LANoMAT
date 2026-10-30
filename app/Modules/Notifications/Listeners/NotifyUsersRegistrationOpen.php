<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Notifications\Notifications\RegistrationOpened;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * In-app "Anmeldung offen" invitation. A core listener (always registered):
 * the bell must work without the optional Discord module, whose own listener
 * only posts the channel announcement. Everyone who does not already hold an
 * active registration for the event is invited; users who disabled the
 * `registration` category are skipped by {@see RegistrationOpened::via()}.
 */
class NotifyUsersRegistrationOpen implements ShouldQueue
{
    public function handle(EventStatusChanged $changed): void
    {
        if ($changed->to !== EventStatus::Registration) {
            return;
        }

        $event = $changed->event;

        $alreadyRegistered = $event->registrations()
            ->where('status', '!=', 'cancelled')
            ->select('user_id');

        User::query()
            ->whereNotIn('id', $alreadyRegistered)
            ->chunkById(200, function ($users) use ($event): void {
                Notification::send($users, new RegistrationOpened($event));
            });
    }
}
