<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Actions;

use App\Models\User;
use App\Modules\Mcp\Models\McpToken;

/** Revoke one agent token (the "Verbundene Geräte" list on /mcp/connect). */
class RevokeMcpToken
{
    public function handle(User $actor, McpToken $token): void
    {
        abort_unless($token->user_id === $actor->getKey() || $actor->isAdmin(), 403);

        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
    }
}
