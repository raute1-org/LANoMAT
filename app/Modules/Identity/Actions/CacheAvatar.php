<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Contracts\AvatarFetcher;
use App\Modules\Identity\Exceptions\AvatarFetchFailed;
use App\Modules\Identity\Support\AvatarProcessor;
use App\Modules\Identity\Support\AvatarStore;
use Illuminate\Support\Facades\DB;

/**
 * Brings the local avatar of one user in line with the remembered Discord source URL.
 *
 * - No source URL (the user has no Discord avatar): the local copy is dropped, the UI shows a monogram.
 * - Source URL: fetched, validated, re-encoded, stored content-addressed. The row switches to the
 *   new file only after it is written, and the replaced file is deleted afterwards.
 * - Any failure leaves the previous file and row untouched and is reported to the caller.
 */
class CacheAvatar
{
    public function __construct(
        private readonly AvatarFetcher $fetcher,
        private readonly AvatarProcessor $processor,
        private readonly AvatarStore $store,
    ) {}

    /**
     * @throws AvatarFetchFailed
     */
    public function handle(User $user): void
    {
        $url = $user->avatar_url;

        if ($url === null || $url === '') {
            $this->clear($user);

            return;
        }

        $processed = $this->processor->handle($this->fetcher->fetch($url)->binary);
        $path = $this->store->pathFor($user->id, $processed->hash, $processed->extension);

        $this->store->disk()->put($path, $processed->binary);

        $replaced = DB::transaction(function () use ($user, $path, $processed): ?string {
            $row = User::query()->lockForUpdate()->find($user->id);

            if ($row === null) {
                return $path;
            }

            $previous = $row->avatar_path;
            $row->forceFill([
                'avatar_path' => $path,
                'avatar_hash' => $processed->hash,
                'avatar_checked_at' => now(),
            ])->saveQuietly();

            $user->setRawAttributes($row->getAttributes(), true);

            return $previous !== $path ? $previous : null;
        });

        $this->store->forget($replaced);
    }

    private function clear(User $user): void
    {
        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => null, 'avatar_hash' => null, 'avatar_checked_at' => now()])->saveQuietly();

        $this->store->forget($previous);
    }
}
