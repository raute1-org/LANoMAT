<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Actions;

use App\Modules\Mcp\Models\McpDeviceCode;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\DeviceFlowError;
use App\Modules\Mcp\Support\McpCodes;
use App\Modules\Mcp\Support\McpConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Step 3: the agent exchanges an approved device code for a bearer token.
 *
 * Failures are collected inside the transaction and thrown after it commits,
 * so a `slow_down`/`authorization_pending` poll still persists its
 * `last_polled_at` and raised interval (same reasoning as the native voice
 * client: throwing inside the closure would roll the update back and the
 * client could hot-loop).
 */
class ExchangeDeviceCode
{
    private const SLOW_DOWN_STEP = 5;

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     *
     * @throws DeviceFlowError
     */
    public function handle(string $deviceCode): array
    {
        $hash = McpCodes::hash($deviceCode);

        $failure = null;
        $result = null;

        DB::transaction(function () use ($hash, &$failure, &$result): void {
            $row = McpDeviceCode::query()->where('device_code_hash', $hash)->lockForUpdate()->first();

            if (! $row instanceof McpDeviceCode || ! hash_equals($row->device_code_hash, $hash)) {
                $failure = new DeviceFlowError('invalid_grant');

                return;
            }

            if ($row->isExpired()) {
                $row->delete();
                $failure = new DeviceFlowError('expired_token');

                return;
            }

            $tooFast = $row->last_polled_at !== null
                && $row->last_polled_at->copy()->addSeconds((int) $row->interval)->isFuture();

            $row->last_polled_at = Carbon::now();

            if ($tooFast) {
                // PostgreSQL's smallint is signed (max 32767), not 65535; clamping
                // above that would 500 and brick the pairing row.
                $row->interval = min(32767, (int) $row->interval + self::SLOW_DOWN_STEP);
                $row->save();
                $failure = new DeviceFlowError('slow_down', (int) $row->interval);

                return;
            }

            if ($row->status === McpDeviceCode::DENIED) {
                $row->delete();
                $failure = new DeviceFlowError('access_denied');

                return;
            }

            if ($row->status !== McpDeviceCode::APPROVED || $row->user_id === null) {
                $row->save();
                $failure = new DeviceFlowError('authorization_pending');

                return;
            }

            $plain = McpCodes::bearerToken();
            $ttl = McpConfig::tokenTtlDays();

            (new McpToken)->forceFill([
                'user_id' => $row->user_id,
                'name' => $row->device_name,
                'token_hash' => McpCodes::hash($plain),
                'abilities' => null,
                'expires_at' => now()->addDays($ttl),
            ])->save();

            $row->delete();

            $result = [
                'access_token' => $plain,
                'token_type' => 'Bearer',
                'expires_in' => $ttl * 86400,
            ];
        });

        if ($failure instanceof DeviceFlowError) {
            throw $failure;
        }

        /** @var array{access_token: string, token_type: string, expires_in: int} $result */
        return $result;
    }
}
