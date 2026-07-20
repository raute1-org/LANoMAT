<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Models;

use App\Models\User;
use Database\Factories\JukeboxSkipVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's vote to skip the currently playing (or queued) item (one row
 * per user per item, see the migration's unique index).
 *
 * @property int $id
 * @property int $jukebox_item_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JukeboxSkipVote extends Model
{
    /** @use HasFactory<JukeboxSkipVoteFactory> */
    use HasFactory;

    protected $fillable = [
        'jukebox_item_id',
        'user_id',
    ];

    /** @return BelongsTo<JukeboxItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(JukeboxItem::class, 'jukebox_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): JukeboxSkipVoteFactory
    {
        return JukeboxSkipVoteFactory::new();
    }
}
