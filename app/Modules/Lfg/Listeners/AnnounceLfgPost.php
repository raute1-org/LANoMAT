<?php

namespace App\Modules\Lfg\Listeners;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Support\DiscordOutboxGuard;
use App\Modules\Lfg\Events\LfgPostCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

class AnnounceLfgPost implements ShouldQueue
{
    public function __construct(
        private readonly DiscordOutboxGuard $guard,
        private readonly DiscordClient $client,
    ) {}

    public function handle(LfgPostCreated $event): void
    {
        $channelId = config('services.discord.announce_channel_id');
        if (blank($channelId)) {
            return;
        }

        $post = $event->post;
        $userName = $post->user()->firstOrFail()->name;

        $content = filled($post->game)
            ? __('discord.lfg.announcement', [
                'user' => $userName,
                'title' => $post->title,
                'game' => $post->game,
            ])
            : __('discord.lfg.announcement_no_game', [
                'user' => $userName,
                'title' => $post->title,
            ]);

        $this->guard->once(
            "lfg-{$post->id}-created",
            'lfg_created',
            fn () => $this->client->sendMessage((string) $channelId, $content),
            channelId: (string) $channelId,
            content: $content,
        );
    }
}
