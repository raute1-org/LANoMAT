<?php

namespace App\Modules\Teams\Models;

use App\Models\User;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $owner_id
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    // owner_id set by CreateTeam action, never client-fillable.
    protected $fillable = ['name', 'tag', 'logo_path'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<TeamMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    /** @return HasMany<TeamJoinRequest, $this> */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(TeamJoinRequest::class);
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
