<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Models\User;
use App\Modules\Identity\Support\AvatarStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the local avatar copy. One controller for both surfaces: the browser route (session) and
 * the native client route (bearer) differ only in their middleware, and both return the same bytes.
 * The content hash is part of the URL, so the response is immutable for a year; a wrong hash, a user
 * without an avatar or a file missing from the disk are all the same 404.
 */
class AvatarController
{
    use ResolvesAuthenticatedUser;

    public function show(Request $request, User $user, string $hash, AvatarStore $store): Response
    {
        Gate::forUser($this->authUser($request))->authorize('view-avatar', $user);

        $path = $store->current($user);

        abort_if($path === null || ! hash_equals((string) $user->avatar_hash, $hash), 404);

        $headers = [
            'Content-Type' => $store->mimeFor($path),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => '"'.$hash.'"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (in_array('"'.$hash.'"', array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, $headers);
        }

        abort_unless($store->disk()->exists($path), 404);

        return response((string) $store->disk()->get($path), 200, $headers);
    }
}
