<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paired native voice client. Only the sha256 digest of the bearer token is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_name
 * @property string $token_hash
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $created_at
 */
class VoiceClientToken extends Model
{
    // Everything here is state set by actions, never by a form.
    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
