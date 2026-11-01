<?php

declare(strict_types=1);

namespace App\Modules\Identity\Jobs;

use App\Models\User;
use App\Modules\Identity\Actions\CacheAvatar;
use App\Modules\Identity\Exceptions\AvatarFetchFailed;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes one user's local avatar copy (dispatched after the Discord login so the login itself
 * never waits for the CDN). Idempotent: the stored file is content-addressed, so a second run
 * with the same source changes nothing. A failed fetch is logged and ends the job without a retry
 * storm; the old file stays and the daily sweep tries again.
 */
class RefreshAvatarJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(CacheAvatar $cache): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        try {
            $cache->handle($user);
        } catch (AvatarFetchFailed $e) {
            Log::info('Avatar refresh skipped, keeping the previous avatar.', ['user_id' => $user->id, 'reason' => $e->getMessage()]);
        }
    }
}
