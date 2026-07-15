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
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Event $event, User $user, array $attributes): LfgPost
    {
        if (! $event->isPubliclyVisible()) {
            throw LfgException::eventNotVisible();
        }

        $durationHours = self::validDurationHours($attributes['duration_hours'] ?? null);

        return DB::transaction(function () use ($event, $user, $attributes, $durationHours): LfgPost {
            // event_id/user_id are always taken from the trusted arguments,
            // never from $attributes — a client could otherwise post as a
            // different user or attach the post to an unrelated event.
            $post = LfgPost::create([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'game' => $attributes['game'] ?? null,
                'title' => $attributes['title'],
                'body' => $attributes['body'] ?? null,
                'slots_needed' => $attributes['slots_needed'] ?? null,
                'expires_at' => now()->addHours($durationHours),
            ]);

            LfgPostCreated::dispatch($post);

            return $post;
        });
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
