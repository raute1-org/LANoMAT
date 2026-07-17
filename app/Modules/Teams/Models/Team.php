<?php

namespace App\Modules\Teams\Models;

use App\Models\User;
use App\Modules\Voice\Domain\VoiceProvider;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $owner_id
 * @property VoiceProvider|null $voice_provider
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    // owner_id set by CreateTeam action, never client-fillable.
    protected $fillable = ['name', 'tag', 'logo_path', 'voice_provider'];

    protected function casts(): array
    {
        return [
            'voice_provider' => VoiceProvider::class,
        ];
    }

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

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (self $team) {
            if ($team->logo_path !== null) {
                Storage::disk('public')->delete($team->logo_path);
            }
        });
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
