<?php

namespace App\Modules\Tournaments\Models;

use App\Models\User;
use App\Modules\Teams\Models\Team;
use App\Modules\Tournaments\Enums\EntryStatus;
use Database\Factories\TournamentEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property EntryStatus $status
 * @property int|null $seed
 * @property Carbon|null $checked_in_at
 * @property array<int, array{user_id: int, name: string}>|null $roster_snapshot
 */
class TournamentEntry extends Model
{
    /** @use HasFactory<TournamentEntryFactory> */
    use HasFactory;

    // status/seed/checked_in_at/roster_snapshot are set only via Actions
    // (later tasks), never client-fillable.
    protected $fillable = [
        'tournament_id',
        'team_id',
        'user_id',
        'display_name',
    ];

    protected function casts(): array
    {
        return [
            'status' => EntryStatus::class,
            'checked_in_at' => 'datetime',
            'roster_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Tournament, $this> */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

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

    protected static function newFactory(): TournamentEntryFactory
    {
        return TournamentEntryFactory::new();
    }
}
