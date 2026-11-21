<?php

namespace App\Modules\Lfg\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Friends\Support\FriendService;
use App\Modules\Games\Models\Game;
use App\Modules\Lfg\Actions\ToggleWantToPlay;
use App\Modules\Lfg\Models\LfgPost;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;

/**
 * Read model of the "Bock auf" signals (LfgPosts recognised by
 * {@see ToggleWantToPlay::isSignal()}) of one event, grouped by game.
 */
final class WantToPlayBoard
{
    private const MAX_NAMES = 3;

    private const MAX_SUGGESTIONS = 8;

    public function __construct(
        private readonly Modules $modules,
        private readonly FriendService $friends,
    ) {}

    /**
     * @return array{games: list<array{game: string, count: int, names: list<string>, mine: bool}>, suggestions: list<string>, hours: int}
     */
    public function for(Event $event, User $viewer): array
    {
        $blocked = $this->modules->enabled(Module::Friends) ? $this->friends->blockedUserIdsEitherWay($viewer) : [];

        $posts = LfgPost::query()
            ->where('event_id', $event->id)
            ->active()
            ->whereNotNull('game')
            ->whereNull('body')
            ->whereNull('slots_needed')
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (LfgPost $post): bool => ToggleWantToPlay::isSignal($post));

        /** @var array<string, array{game: string, users: array<int, string>, mine: bool}> $groups */
        $groups = [];
        foreach ($posts as $post) {
            $game = trim((string) $post->game);
            $key = mb_strtolower($game);
            // Most recent first, so the first post of a group fixes the display spelling.
            $groups[$key] ??= ['game' => $game, 'users' => [], 'mine' => false];

            if ($post->user_id === $viewer->id) {
                $groups[$key]['mine'] = true;
            }
            if (in_array($post->user_id, $blocked, true) && $post->user_id !== $viewer->id) {
                continue;
            }
            $groups[$key]['users'][$post->user_id] ??= (string) $post->user?->name;
        }

        $games = [];
        foreach ($groups as $group) {
            if ($group['users'] === []) {
                continue;
            }
            $names = [];
            foreach ($group['users'] as $userId => $name) {
                if ($userId !== $viewer->id) {
                    $names[] = $name;
                }
            }
            $games[] = [
                'game' => $group['game'],
                'count' => count($group['users']),
                'names' => array_slice($names, 0, self::MAX_NAMES),
                'mine' => $group['mine'],
            ];
        }

        usort($games, fn (array $a, array $b): int => [$b['count'], mb_strtolower($a['game'])] <=> [$a['count'], mb_strtolower($b['game'])]);

        return [
            'games' => $games,
            'suggestions' => $this->suggestions($games),
            'hours' => ToggleWantToPlay::HOURS,
        ];
    }

    /**
     * @param  list<array{game: string, count: int, names: list<string>, mine: bool}>  $games
     * @return list<string>
     */
    private function suggestions(array $games): array
    {
        $taken = array_map(fn (array $g): string => mb_strtolower($g['game']), $games);

        $suggestions = [];
        foreach (Game::query()->orderBy('name')->pluck('name') as $name) {
            $name = (string) $name;
            if (in_array(mb_strtolower($name), $taken, true)) {
                continue;
            }
            $suggestions[] = $name;
            if (count($suggestions) === self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $suggestions;
    }
}
