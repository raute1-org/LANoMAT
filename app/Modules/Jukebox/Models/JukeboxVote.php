<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Models;

use App\Models\User;
use Database\Factories\JukeboxVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's up-vote for a queued item (one row per user per item, see the
 * migration's unique index).
 *
 * @property int $id
 * @property int $jukebox_item_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JukeboxVote extends Model
{
    /** @use HasFactory<JukeboxVoteFactory> */
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

    protected static function newFactory(): JukeboxVoteFactory
    {
        return JukeboxVoteFactory::new();
    }
}
