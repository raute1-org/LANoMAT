<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Where avatars live and how they are addressed. Files are on the PRIVATE local disk as
 * `avatars/<user id>-<content hash>.<ext>`; they are only ever served through the authenticated
 * routes (session and bearer), never through a public URL. The hash is part of the URL, so a
 * changed avatar is a new URL and long-lived caches stay correct.
 */
class AvatarStore
{
    public const HASH_PATTERN = '[a-f0-9]{16}';

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public function pathFor(int $userId, string $hash, string $extension): string
    {
        return 'avatars/'.$userId.'-'.$hash.'.'.$extension;
    }

    public function has(User $user): bool
    {
        return $this->current($user) !== null;
    }

    /** The relative URL a logged-in browser loads, or null when the user has no local avatar. */
    public function webUrl(User $user): ?string
    {
        return $this->current($user) === null ? null : route('avatars.show', [$user->id, $user->avatar_hash], false);
    }

    /** The bearer-API path the native client fetches, or null when the user has no local avatar. */
    public function nativeUrl(User $user): ?string
    {
        // The bearer API only exists while the WebVoice module is routable.
        return $this->current($user) === null || ! Route::has('voice-client.v1.avatars.show') ? null : route('voice-client.v1.avatars.show', [$user->id, $user->avatar_hash], false);
    }

    public function mimeFor(string $path): string
    {
        return str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg';
    }

    /** The stored path when the row carries both a path and a hash, otherwise null (monogram fallback). */
    public function current(User $user): ?string
    {
        $path = $user->avatar_path;
        $hash = $user->avatar_hash;

        return $path !== null && $path !== '' && $hash !== null && $hash !== '' ? $path : null;
    }

    public function forget(?string $path): void
    {
        if ($path !== null && $path !== '' && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }
}
