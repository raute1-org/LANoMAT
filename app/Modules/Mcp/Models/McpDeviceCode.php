<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-time pairing code for the agent installer. State is only ever written
 * by the device-flow Actions; only hashes of the two codes are persisted.
 *
 * @property string $device_name
 * @property string $status
 * @property int|null $user_id
 * @property int $interval
 * @property Carbon|null $last_polled_at
 * @property Carbon $expires_at
 */
class McpDeviceCode extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    protected $guarded = ['*'];

    protected $hidden = ['device_code_hash', 'user_code_hash'];

    protected function casts(): array
    {
        return [
            'last_polled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
