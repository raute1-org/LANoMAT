<?php

namespace App\Modules\Lfg\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Exceptions\LfgException;
use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Switches a user's "Bock auf <game>" signal on or off. A signal is a plain
 * {@see LfgPost} without body and slots whose title is the translated
 * "Bock auf :game"; it lives {@see self::HOURS} hours.
 */
class ToggleWantToPlay
{
    public const HOURS = 3;

    private const MAX_GAME_LENGTH = 80;

    /**
     * @return bool the new state: true = signal active
     */
    public function handle(User $user, Event $event, string $game): bool
    {
        if (! $event->isPubliclyVisible()) {
            throw LfgException::eventNotVisible();
        }

        $game = trim($game);
        if ($game === '' || mb_strlen($game) > self::MAX_GAME_LENGTH) {
            throw LfgException::invalidTitle();
        }

        return DB::transaction(function () use ($user, $event, $game): bool {
            $existing = LfgPost::query()
                ->where('event_id', $event->id)
                ->where('user_id', $user->id)
                ->active()
                ->whereNotNull('game')
                ->get()
                ->filter(fn (LfgPost $post): bool => self::isSignal($post)
                    && mb_strtolower(trim((string) $post->game)) === mb_strtolower($game));

            if ($existing->isNotEmpty()) {
                foreach ($existing as $post) {
                    Gate::forUser($user)->authorize('delete', $post);
                    $post->delete();
                }

                return false;
            }

            Gate::forUser($user)->authorize('create', LfgPost::class);

            $post = new LfgPost([
                'event_id' => $event->id,
                'game' => $game,
                'title' => self::titleFor($game),
                'body' => null,
                'slots_needed' => null,
            ]);
            $post->forceFill([
                'user_id' => $user->id,
                'expires_at' => now()->addHours(self::HOURS),
            ]);
            $post->save();

            return true;
        });
    }

    public static function titleFor(string $game): string
    {
        return trans('lfg.want_to_play.title', ['game' => $game]);
    }

    public static function isSignal(LfgPost $post): bool
    {
        return $post->game !== null
            && $post->body === null
            && $post->slots_needed === null
            && $post->title === self::titleFor(trim($post->game));
    }
}
