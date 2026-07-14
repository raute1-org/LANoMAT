<?php

namespace App\Modules\Tournaments\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property TournamentFormat $format
 * @property TournamentStatus $status
 * @property array<string, mixed> $settings
 * @property Carbon $starts_at
 * @property Carbon|null $checkin_opens_at
 * @property Carbon|null $checkin_closes_at
 * @property int|null $winner_entry_id
 */
class Tournament extends Model
{
    /** @use HasFactory<TournamentFactory> */
    use HasFactory;

    // status/winner_entry_id are set only via Actions (later tasks), never
    // client-fillable.
    protected $fillable = [
        'event_id',
        'game_id',
        'name',
        'format',
        'team_size',
        'max_entries',
        'rules',
        'starts_at',
        'checkin_opens_at',
        'checkin_closes_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'format' => TournamentFormat::class,
            'status' => TournamentStatus::class,
            'settings' => 'array',
            'starts_at' => 'datetime',
            'checkin_opens_at' => 'datetime',
            'checkin_closes_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<TournamentEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    /** @return HasMany<GameMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }

    /** @return BelongsTo<TournamentEntry, $this> */
    public function winnerEntry(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'winner_entry_id');
    }

    protected static function newFactory(): TournamentFactory
    {
        return TournamentFactory::new();
    }
}
