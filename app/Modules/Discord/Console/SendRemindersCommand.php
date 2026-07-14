<?php

namespace App\Modules\Discord\Console;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'lanomat:send-reminders';

    protected $description = 'Send Discord reminders for upcoming events (24h / 1h), deduplicated via the outbox.';

    public function handle(DiscordOutboxGuard $guard, DiscordClient $client): int
    {
        $channelId = config('services.discord.announce_channel_id');
        if (blank($channelId)) {
            return self::SUCCESS;
        }

        $upcoming = Event::query()
            ->whereIn('status', [
                EventStatus::Announced->value,
                EventStatus::Registration->value,
                EventStatus::Live->value,
            ])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours(25))
            ->get();

        foreach ($upcoming as $event) {
            $hoursUntil = now()->diffInHours($event->starts_at, false);

            // 24h window: 23-25h out. 1h window: <=1h out.
            $reminder = match (true) {
                $hoursUntil <= 1 => ['1h', 1],
                $hoursUntil >= 23 && $hoursUntil <= 25 => ['24h', 24],
                default => null,
            };

            if ($reminder === null) {
                continue;
            }

            [$suffix, $hours] = $reminder;

            $content = __('discord.reminder', ['event' => $event->name, 'hours' => $hours]);

            $guard->once(
                "event-{$event->id}-reminder-{$suffix}",
                "reminder_{$suffix}",
                fn () => $client->sendMessage((string) $channelId, $content),
                channelId: (string) $channelId,
                content: $content,
            );
        }

        return self::SUCCESS;
    }
}
