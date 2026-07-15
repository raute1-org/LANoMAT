<?php

namespace App\Modules\Discord\Interactions\Commands;

use App\Modules\Discord\Interactions\InteractionPayload;
use App\Modules\Discord\Interactions\InteractionResponse;
use App\Modules\Events\Support\CurrentEvent;
use App\Modules\Lfg\Actions\CreateLfgPost;
use App\Modules\Lfg\Exceptions\LfgException;
use App\Modules\Lfg\Models\LfgPost;

/**
 * `/lfg list|create` — thin wrappers around the existing Lfg module:
 *
 * - `list`: {@see CurrentEvent} resolver + a plain query over its active
 *   {@see LfgPost}s, mirroring {@see ScheduleCommand}.
 * - `create`: maps the Discord user to a local User (never trusting a
 *   client-supplied id — only the `discord_id` mapping) and, if mapped,
 *   calls {@see CreateLfgPost} for the current publicly-visible event.
 */
class LfgCommand
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return match (InteractionPayload::subcommand($payload)) {
            'list' => $this->list(),
            'create' => $this->create($payload),
            default => InteractionResponse::message(__('discord.unknown_command')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function list(): array
    {
        $event = app(CurrentEvent::class)->get();

        if ($event === null) {
            return InteractionResponse::message(__('discord.commands.lfg.list.no_current_event'));
        }

        $posts = LfgPost::query()
            ->where('event_id', $event->id)
            ->active()
            ->with('user')
            ->orderBy('created_at')
            ->get();

        if ($posts->isEmpty()) {
            return InteractionResponse::message(__('discord.commands.lfg.list.none'));
        }

        $content = $posts
            ->map(function (LfgPost $post): string {
                $user = $post->user?->name;

                return $post->game !== null
                    ? __('discord.commands.lfg.list.item', [
                        'title' => $post->title,
                        'game' => $post->game,
                        'user' => $user,
                    ])
                    : __('discord.commands.lfg.list.item_no_game', [
                        'title' => $post->title,
                        'user' => $user,
                    ]);
            })
            ->implode("\n");

        return InteractionResponse::message($content);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function create(array $payload): array
    {
        $user = InteractionPayload::mappedUser($payload);

        if ($user === null) {
            return InteractionResponse::message(__('discord.not_linked'));
        }

        $event = app(CurrentEvent::class)->get();

        if ($event === null) {
            return InteractionResponse::message(__('discord.commands.lfg.create.no_current_event'));
        }

        $title = InteractionPayload::subcommandOption($payload, 'title');
        $game = InteractionPayload::subcommandOption($payload, 'game');

        try {
            app(CreateLfgPost::class)->handle($event, $user, [
                'title' => $title,
                'game' => is_string($game) ? $game : null,
            ]);
        } catch (LfgException $e) {
            return InteractionResponse::message(__($e->translationKey));
        }

        return InteractionResponse::message(__('discord.commands.lfg.create.success', [
            'title' => is_string($title) ? $title : '',
        ]));
    }
}
