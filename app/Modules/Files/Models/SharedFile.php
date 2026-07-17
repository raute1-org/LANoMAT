<?php

namespace App\Modules\Files\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Actions\UploadSharedFile;
use App\Modules\Files\Enums\FileVisibility;
use Database\Factories\SharedFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $event_id
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string|null $mime
 * @property FileVisibility $visibility
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class SharedFile extends Model
{
    /** @use HasFactory<SharedFileFactory> */
    use HasFactory;

    /**
     * `disk`, `path`, `size_bytes`, `mime`, `visibility`, `reviewed_by` and
     * `reviewed_at` are deliberately excluded — they are set only by
     * {@see UploadSharedFile} (and, for the review fields, the
     * approve/reject action added in Task 6) via forceFill(), never
     * mass-assigned from client input. In particular `user_id` (ownership)
     * is NOT fillable: the uploading action always sets it from the
     * authenticated actor, never from request input.
     */
    protected $fillable = [
        'event_id',
        'user_id',
        'original_name',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => FileVisibility::class,
            'size_bytes' => 'integer',
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
        return $this->belongsTo(User::class, 'user_id');
    }

    protected static function newFactory(): SharedFileFactory
    {
        return SharedFileFactory::new();
    }
}
