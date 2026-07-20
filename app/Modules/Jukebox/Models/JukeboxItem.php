<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Jukebox\Enums\QueueItemStatus;
use Database\Factories\JukeboxItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One track in an event's hall playlist.
 *
 * `status` and `played_at` are deliberately excluded from `$fillable` — they
 * are lifecycle/privilege fields advanced only by playback actions (queueing,
 * starting playback, marking played/skipped) in a later task, never
 * mass-assigned from client input.
 *
 * @property int $id
 * @property int $event_id
 * @property int $added_by
 * @property string $uri
 * @property string $title
 * @property string|null $artist
 * @property int|null $duration_seconds
 * @property string|null $image_url
 * @property QueueItemStatus $status
 * @property Carbon|null $played_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JukeboxItem extends Model
{
    /** @use HasFactory<JukeboxItemFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'added_by',
        'uri',
        'title',
        'artist',
        'duration_seconds',
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'status' => QueueItemStatus::class,
            'played_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** @return HasMany<JukeboxVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(JukeboxVote::class);
    }

    /** @return HasMany<JukeboxSkipVote, $this> */
    public function skipVotes(): HasMany
    {
        return $this->hasMany(JukeboxSkipVote::class);
    }

    public function voteCount(): int
    {
        return $this->votes()->count();
    }

    protected static function newFactory(): JukeboxItemFactory
    {
        return JukeboxItemFactory::new();
    }
}
