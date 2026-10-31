<?php

namespace App\Modules\Tournaments\Models;

use App\Models\User;
use App\Modules\Tournaments\Actions\OverrideMatchResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row written by {@see OverrideMatchResult}
 * for every orga decision on a match. Written only by that Action.
 *
 * @property int $match_id
 * @property int|null $user_id
 * @property string $previous_status
 * @property int|null $previous_score1
 * @property int|null $previous_score2
 * @property int|null $previous_winner_entry_id
 * @property int $score1
 * @property int $score2
 * @property string|null $reason
 */
class MatchOverride extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<GameMatch, $this> */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
