<?php

namespace App\Modules\Discord\Listeners;

use App\Models\User;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Notifications\RegistrationOpened;
use App\Modules\Registration\Enums\RegistrationStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class AnnounceRegistrationOpen implements ShouldQueue
{
    public function __construct(
        private readonly DiscordOutboxGuard $guard,
        private readonly DiscordClient $client,
    ) {}

    public function handle(EventStatusChanged $event): void
    {
        if ($event->to !== EventStatus::Registration) {
            return;
        }

        $this->notifyBell($event->event);

        $channelId = config('services.discord.announce_channel_id');
        if (blank($channelId)) {
            return;
        }

        $content = __('discord.registration_open', ['event' => $event->event->name]);

        $this->guard->once(
            "event-{$event->event->id}-registration-open",
            'registration_open',
            fn () => $this->client->sendMessage((string) $channelId, $content),
            channelId: (string) $channelId,
            content: $content,
        );
    }

    /**
     * The "Discord verstärkt, ersetzt nie" fold-in (roadmap insight): the
     * bell is the source of truth, so the event's existing confirmed
     * registrants get a database notification here — independent of, and in
     * addition to, the channel-wide Discord broadcast above (which remains
     * the mirror, unchanged).
     */
    private function notifyBell(Event $event): void
    {
        $registrants = User::query()
            ->whereIn('id', $event->registrations()
                ->where('status', RegistrationStatus::Confirmed)
                ->pluck('user_id'))
            ->get();

        if ($registrants->isEmpty()) {
            return;
        }

        Notification::send($registrants, new RegistrationOpened($event));
    }
}
