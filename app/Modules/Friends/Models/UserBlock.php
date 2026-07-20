<?php

declare(strict_types=1);

namespace App\Modules\Friends\Models;

use App\Models\User;
use Database\Factories\UserBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A directed block: `blocker_id` has blocked `blocked_id`.
 *
 * @property int $id
 * @property int $blocker_id
 * @property int $blocked_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserBlock extends Model
{
    /** @use HasFactory<UserBlockFactory> */
    use HasFactory;

    protected $fillable = [
        'blocker_id',
        'blocked_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    /** @return BelongsTo<User, $this> */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }

    protected static function newFactory(): UserBlockFactory
    {
        return UserBlockFactory::new();
    }
}
