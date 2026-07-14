<?php

namespace App\Modules\Teams\Models;

use App\Models\User;
use App\Modules\Teams\Enums\TeamRole;
use Database\Factories\TeamMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TeamRole $role
 */
class TeamMember extends Model
{
    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory;

    // role is not client-fillable; only ever set via Member actions.
    protected $fillable = ['team_id', 'user_id'];

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
            'role' => TeamRole::class,
        ];
    }

    protected static function newFactory(): TeamMemberFactory
    {
        return TeamMemberFactory::new();
    }
}
