<?php

namespace App\Modules\Teams\Models;

use App\Models\User;
use App\Modules\Teams\Enums\JoinRequestStatus;
use Database\Factories\TeamJoinRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamJoinRequest extends Model
{
    /** @use HasFactory<TeamJoinRequestFactory> */
    use HasFactory;

    // status is not client-fillable; only ever set via Member actions.
    protected $fillable = ['team_id', 'user_id', 'message'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'status' => JoinRequestStatus::class,
        ];
    }

    protected static function newFactory(): TeamJoinRequestFactory
    {
        return TeamJoinRequestFactory::new();
    }
}
