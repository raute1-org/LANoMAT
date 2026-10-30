<?php

namespace App\Modules\Tournaments\Notifications;

use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Discord\Support\MatchEmbed;
use App\Modules\Tournaments\Listeners\NotifyRosterOnMatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies a match's roster members that their match is now playable — the
 * bell counterpart to the Discord match-channel embed
 * ({@see MatchEmbed}), which previously was the *only* place this
 * information lived. The bell is the source of truth (always lands via
 * `database`); the Discord DM is a mirror gated by the `match` category
 * preference (see {@see DiscordChannel}).
 *
 * Queued (one job per recipient and channel) so a Discord outage can never
 * stop the bell rows of the remaining roster members.
 */
class MatchReadyBell extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly string $category;

    public function __construct(
        public readonly GameMatch $match,
    ) {
        $this->category = 'match';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return app(Modules::class)->enabled(Module::Discord) ? ['database', DiscordChannel::class] : ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => __('tournaments.notifications.match_ready.title'),
            'body' => $this->body(),
        ];
    }

    public function toDiscord(object $notifiable): string
    {
        return $this->body();
    }

    private function body(): string
    {
        $unknownOpponent = __('discord.match_channel.unknown_opponent');
        $entry1DisplayName = $this->match->entry1?->display_name;
        $entry2DisplayName = $this->match->entry2?->display_name;
        $entry1Name = $entry1DisplayName ?? $unknownOpponent;
        $entry2Name = $entry2DisplayName ?? $unknownOpponent;

        $url = route('tournaments.show', $this->match->tournament);

        $body = __('tournaments.notifications.match_ready.body', [
            'entry1' => $entry1Name,
            'entry2' => $entry2Name,
            'url' => $url,
        ]);

        $voiceLink = MatchEmbed::voiceLink($this->match, $entry1Name, $entry2Name);

        if ($voiceLink !== null) {
            $body .= "\n".$voiceLink;
        }

        return $body;
    }
}
