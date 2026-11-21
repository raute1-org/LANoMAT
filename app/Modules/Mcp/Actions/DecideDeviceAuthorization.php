<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Actions;

use App\Models\User;
use App\Modules\Mcp\Models\McpDeviceCode;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Support\Facades\DB;

/** Step 2: the logged-in user approves or denies a pending device code. */
class DecideDeviceAuthorization
{
    public function handle(User $user, string $userCode, bool $approve): bool
    {
        $code = McpCodes::normalize($userCode);

        if ($code === null) {
            return false;
        }

        return DB::transaction(function () use ($user, $code, $approve): bool {
            $row = McpDeviceCode::query()
                ->where('user_code_hash', McpCodes::hash($code))
                ->lockForUpdate()
                ->first();

            if (! $row instanceof McpDeviceCode || $row->isExpired() || $row->status !== McpDeviceCode::PENDING) {
                return false;
            }

            $row->forceFill([
                'status' => $approve ? McpDeviceCode::APPROVED : McpDeviceCode::DENIED,
                'user_id' => $approve ? $user->getKey() : null,
            ])->save();

            return true;
        });
    }
}
