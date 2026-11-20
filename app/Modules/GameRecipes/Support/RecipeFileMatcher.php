<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Finds the LAN copies of the files a recipe needs.
 *
 * Only approved SharedFiles of publicly visible events are candidates: a
 * recipe must never make another user's pending upload (or a draft event)
 * visible. A pinned `shared_file_id` wins; otherwise the filename patterns
 * are applied case-insensitively, newest/largest first, and verified in PHP
 * with fnmatch after a coarse SQL prefilter.
 */
final class RecipeFileMatcher
{
    public function match(GameRecipeFile $entry, User $viewer, ?Event $event = null): ?SharedFile
    {
        if ($entry->shared_file_id !== null) {
            $pinned = SharedFile::query()
                ->whereKey($entry->shared_file_id)
                ->where('visibility', FileVisibility::Approved->value)
                ->whereHas('event', fn (Builder $query) => $query->where('status', '!=', EventStatus::Draft->value))
                ->when(($eventId = $event?->getKey()) !== null, fn (Builder $query) => $query->where('event_id', $eventId))
                ->first();

            if ($pinned instanceof SharedFile) {
                return $pinned;
            }
        }

        $patterns = $entry->patterns();

        if ($patterns === []) {
            return null;
        }

        // First the honest glob->LIKE pass, then a contains fallback for
        // uploads that added a suffix. They are separate queries so a busy
        // share's contains-hits can never crowd the real match out of the
        // limit(50) window (review R13).
        $candidates = $this->query($event)
            ->where(function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhere('original_name', 'ilike', $this->sqlLike($pattern));
                }
            })
            ->orderByDesc('size_bytes')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->nameMatches($candidate->original_name, $patterns)) {
                return $candidate;
            }
        }

        $fallback = $this->query($event)
            ->where(function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $bare = preg_replace('/[*?].*$/', '', $pattern);

                    if (is_string($bare) && strlen($bare) >= 3) {
                        $query->orWhere('original_name', 'ilike', '%'.$bare.'%');
                    }
                }
            })
            ->orderByDesc('size_bytes')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($fallback as $candidate) {
            if ($this->nameMatches($candidate->original_name, $patterns)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, SharedFile> keyed by GameRecipeFile id
     */
    public function matchesFor(GameRecipe $recipe, User $viewer, ?Event $event = null): array
    {
        $matches = [];

        foreach ($recipe->files as $entry) {
            $file = $this->match($entry, $viewer, $event);

            if ($file instanceof SharedFile) {
                $matches[$entry->id] = $file;
            }
        }

        return $matches;
    }

    /** @return Builder<SharedFile> */
    public function query(?Event $event): Builder
    {
        return SharedFile::query()
            ->where('visibility', FileVisibility::Approved->value)
            ->whereHas('event', fn (Builder $query) => $query->where('status', '!=', EventStatus::Draft->value))
            ->when(($eventId = $event?->getKey()) !== null, fn (Builder $query) => $query->where('event_id', $eventId))
            ->with('event');
    }

    /**
     * @param  list<string>  $patterns
     */
    public function nameMatches(string $name, array $patterns): bool
    {
        $name = mb_strtolower($name);

        foreach ($patterns as $pattern) {
            if (fnmatch(mb_strtolower($pattern), $name)) {
                return true;
            }

            // A bare term without a wildcard still means "this file".
            if (! Str::contains($pattern, ['*', '?']) && mb_strtolower($pattern) === $name) {
                return true;
            }
        }

        return false;
    }

    private function sqlLike(string $pattern): string
    {
        $pattern = mb_strtolower($pattern);
        $out = '';

        foreach (str_split($pattern) as $char) {
            $out .= match ($char) {
                '*' => '%',
                '?' => '_',
                '%', '_', '\\' => '\\'.$char,
                default => $char,
            };
        }

        return $out;
    }
}
