<?php

namespace App\Modules\Lfg\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Lfg\Events\LfgPostCreated;
use App\Modules\Lfg\Exceptions\LfgException;
use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Support\Facades\DB;

class CreateLfgPost
{
    /**
     * Default lifetime of an LFG post when the caller does not request a
     * specific `duration_hours`.
     */
    private const DEFAULT_DURATION_HOURS = 3;

    /**
     * Matches the web-form `CreateLfgPostRequest` rule and the `title`
     * column width — enforced here too so that non-HTTP callers (e.g. the
     * `/lfg create` Discord command) can't bypass it and overflow the
     * varchar column.
     */
    private const MAX_TITLE_LENGTH = 120;

    /**
     * Matches the `game` column width (a plain `string()` migration column).
     */
    private const MAX_GAME_LENGTH = 255;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Event $event, User $user, array $attributes): LfgPost
    {
        if (! $event->isPubliclyVisible()) {
            throw LfgException::eventNotVisible();
        }

        $title = self::validTitle($attributes['title'] ?? null);
        $game = self::validGame($attributes['game'] ?? null);
        $durationHours = self::validDurationHours($attributes['duration_hours'] ?? null);

        return DB::transaction(function () use ($event, $user, $attributes, $title, $game, $durationHours): LfgPost {
            // event_id is always taken from the trusted argument, never
            // from $attributes — a client could otherwise attach the post
            // to an unrelated event.
            $post = new LfgPost([
                'event_id' => $event->id,
                'game' => $game,
                'title' => $title,
                'body' => $attributes['body'] ?? null,
                'slots_needed' => $attributes['slots_needed'] ?? null,
            ]);

            // user_id (ownership) and expires_at (lifecycle) are
            // intentionally NOT fillable on LfgPost (see the model), so
            // they're set here via forceFill() from the trusted arguments —
            // never from client-supplied input (forceFill bypasses only
            // $fillable, not phpstan's int<0,max>/Carbon narrowing that a
            // direct property write would otherwise trip).
            $post->forceFill([
                'user_id' => $user->id,
                'expires_at' => now()->addHours($durationHours),
            ]);
            $post->save();

            LfgPostCreated::dispatch($post);

            return $post;
        });
    }

    private static function validTitle(mixed $title): string
    {
        if (! is_string($title) || trim($title) === '') {
            throw LfgException::invalidTitle();
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw LfgException::invalidTitle();
        }

        return $title;
    }

    /**
     * Reuses {@see LfgException::invalidTitle()} — the module has no
     * dedicated "invalid game" error, and an overlong `game` is the same
     * class of problem (a descriptive field exceeding its column width).
     */
    private static function validGame(mixed $game): ?string
    {
        if ($game === null) {
            return null;
        }

        if (! is_string($game) || mb_strlen($game) > self::MAX_GAME_LENGTH) {
            throw LfgException::invalidTitle();
        }

        return $game;
    }

    /**
     * Validates a caller-supplied `duration_hours`, falling back to the
     * module default when absent. Rejects non-positive values rather than
     * silently clamping them, since a zero/negative duration would create a
     * post that is immediately eligible for pruning.
     */
    private static function validDurationHours(mixed $durationHours): int
    {
        if ($durationHours === null) {
            return self::DEFAULT_DURATION_HOURS;
        }

        if (! is_numeric($durationHours) || (int) $durationHours < 1) {
            throw LfgException::invalidDuration();
        }

        return (int) $durationHours;
    }
}
