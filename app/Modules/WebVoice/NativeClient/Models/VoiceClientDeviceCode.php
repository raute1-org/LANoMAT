<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One pending device authorization (RFC 8628 style). Holds digests only.
 *
 * @property int $id
 * @property string $device_code_hash
 * @property string $user_code_hash
 * @property string $device_name
 * @property string $status
 * @property int|null $user_id
 * @property int $interval
 * @property CarbonInterface|null $last_polled_at
 * @property CarbonInterface $expires_at
 */
class VoiceClientDeviceCode extends Model
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
