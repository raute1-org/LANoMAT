<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Identity\Actions\CacheAvatar;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Keeps the local avatar copies current. The daily schedule refreshes users who are registered for
 * a running or upcoming event or logged in during the last 30 days (and were not checked within
 * the last 20 hours); `--all` refreshes every user that has a Discord avatar (the one-time run after
 * the deploy). Users are processed in chunks with a pause between CDN requests, and one failing
 * user never fails the run.
 */
class RefreshAvatarsCommand extends Command
{
    protected $signature = 'lanomat:refresh-avatars
        {--all : Refresh every user, not only the recently active ones}
        {--user= : Refresh a single user id}
        {--pause=150 : Milliseconds to wait between CDN requests}';

    protected $description = 'Fetch Discord avatars into the local store (daily for active users, --all for everybody).';

    public function handle(CacheAvatar $cache): int
    {
        $pause = max(0, (int) $this->option('pause'));
        $done = 0;
        $failed = 0;

        $this->query()->chunkById(100, function ($users) use ($cache, $pause, &$done, &$failed): void {
            foreach ($users as $user) {
                try {
                    $cache->handle($user);
                    $done++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->components->warn("User {$user->id}: {$e->getMessage()}");
                }

                if ($pause > 0) {
                    usleep($pause * 1000);
                }
            }
        });

        $this->components->info("Avatars refreshed: {$done}, failed: {$failed}.");

        return self::SUCCESS;
    }

    /** @return Builder<User> */
    private function query(): Builder
    {
        $query = User::query();

        $only = $this->option('user');

        if (is_scalar($only) && (string) $only !== '') {
            return $query->whereKey((int) $only);
        }

        // Nothing to do for users without a Discord avatar and without a stale local copy.
        $query->where(fn (Builder $q) => $q->whereNotNull('avatar_url')->orWhereNotNull('avatar_path'));

        if ($this->option('all') === true) {
            return $query;
        }

        $upcoming = EventRegistration::query()
            ->select('user_id')
            ->whereHas('event', fn (Builder $e) => $e->whereIn('status', [EventStatus::Announced, EventStatus::Registration, EventStatus::Live]));

        return $query
            ->where(fn (Builder $q) => $q
                ->whereIn('id', $upcoming)
                ->orWhere('last_login_at', '>=', now()->subDays(30)))
            ->where(fn (Builder $q) => $q
                ->whereNull('avatar_checked_at')
                ->orWhere('avatar_checked_at', '<', now()->subHours(20)));
    }
}
