<?php

namespace App\Modules\Infoscreen\Notifications;

use App\Models\User;
use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Infoscreen\Actions\PingOrga;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies an orga/admin/helper user that a participant pressed "Orga
 * rufen" — the caller's seat (if seated) and up to three optional words are
 * carried along so the recipient knows where to go and roughly why, without
 * any ticket/queue system behind it (see {@see PingOrga}).
 * Bell is the source of truth (`database` always lands); the Discord DM
 * mirrors only per the `orga_ping` category preference.
 */
class OrgaPinged extends Notification
{
    use Queueable;

    public readonly string $category;

    public function __construct(
        public readonly User $caller,
        public readonly ?string $seatLabel,
        public readonly ?string $words,
    ) {
        $this->category = 'orga_ping';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', DiscordChannel::class];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => __('infoscreen.orga_ping.title'),
            'body' => $this->body(),
        ];
    }

    public function toDiscord(object $notifiable): string
    {
        return $this->body();
    }

    private function body(): string
    {
        return __('infoscreen.orga_ping.body', [
            'name' => $this->caller->name,
            'seat' => $this->seatLabel ?? __('infoscreen.orga_ping.no_seat'),
            'words' => $this->words ?? __('infoscreen.orga_ping.no_words'),
        ]);
    }
}
