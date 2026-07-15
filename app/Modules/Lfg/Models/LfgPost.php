<?php

namespace App\Modules\Lfg\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use Database\Factories\LfgPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $game
 * @property string $title
 * @property string|null $body
 * @property int|null $slots_needed
 * @property Carbon $expires_at
 */
class LfgPost extends Model
{
    /** @use HasFactory<LfgPostFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'user_id',
        'game',
        'title',
        'body',
        'slots_needed',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'slots_needed' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<LfgPost>  $query
     * @return Builder<LfgPost>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    protected static function newFactory(): LfgPostFactory
    {
        return LfgPostFactory::new();
    }
}
