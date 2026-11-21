<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Actions;

use App\Models\User;
use App\Modules\Mcp\Models\McpDeviceCode;
use App\Modules\Mcp\Support\McpCodes;

/** Resolve a typed user code to the pending pairing row (browser side). */
class ResolveUserCode
{
    public function handle(User $user, string $input): ?McpDeviceCode
    {
        $code = McpCodes::normalize($input);

        if ($code === null) {
            return null;
        }

        $row = McpDeviceCode::query()
            ->where('user_code_hash', McpCodes::hash($code))
            ->where('status', McpDeviceCode::PENDING)
            ->where('expires_at', '>', now())
            ->first();

        return $row;
    }
}
