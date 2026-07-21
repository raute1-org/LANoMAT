<?php

namespace App\Modules\Gallery\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use Database\Factories\EventPhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $event_id
 * @property int $uploaded_by
 * @property string $path
 * @property string $thumb_path
 * @property int $width
 * @property int $height
 * @property string|null $caption
 * @property bool $is_highlight
 * @property PhotoVisibility $visibility
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class EventPhoto extends Model
{
    /** @use HasFactory<EventPhotoFactory> */
    use HasFactory;

    /**
     * `path`, `thumb_path`, `width`, `height`, `visibility`, `is_highlight`,
     * `reviewed_by` and `reviewed_at` are deliberately excluded — set only by
     * the Gallery actions via forceFill() from the trusted authenticated
     * actor, never mass-assigned.
     */
    protected $fillable = [
        'event_id',
        'uploaded_by',
        'caption',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => PhotoVisibility::class,
            'is_highlight' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected static function newFactory(): EventPhotoFactory
    {
        return EventPhotoFactory::new();
    }
}
