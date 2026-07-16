<?php

namespace App\Modules\Schedule\Models;

use App\Models\User;
use Database\Factories\ScheduleItemFavoriteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $schedule_item_id
 * @property int $user_id
 * @property Carbon|null $reminded_at
 */
class ScheduleItemFavorite extends Model
{
    /** @use HasFactory<ScheduleItemFavoriteFactory> */
    use HasFactory;

    /**
     * `user_id` (ownership) and `reminded_at` (reminder dedup stamp) are
     * deliberately excluded from mass-assignment — they must only ever be
     * set via explicit property assignment from a trusted source (the
     * `FavoriteScheduleItem` action and `SendScheduleRemindersCommand`
     * respectively), never through a client-supplied `fill()`.
     */
    protected $fillable = [
        'schedule_item_id',
    ];

    protected function casts(): array
    {
        return [
            'reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ScheduleItem, $this> */
    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(ScheduleItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): ScheduleItemFavoriteFactory
    {
        return ScheduleItemFavoriteFactory::new();
    }
}
