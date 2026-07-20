<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;

/**
 * Resolves the display name to show for a user in a given provider context
 * (e.g. inside a Steam-specific view). Falls back to the LANoMAT name
 * whenever there is no context, no linked account for it, or the linked
 * account has no nickname.
 *
 * Pure and IO-free: it only reads the already-loaded relation exposed via
 * {@see User::linkedAccount()}, never issues its own queries.
 */
final class DisplayNameResolver
{
    public function resolve(User $user, ?LinkedAccountProvider $context): string
    {
        if ($context !== null) {
            $nickname = $user->linkedAccount($context)?->nickname;

            if ($nickname !== null && $nickname !== '') {
                return $nickname;
            }
        }

        return $user->name;
    }
}
