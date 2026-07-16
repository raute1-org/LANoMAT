<?php

namespace App\Modules\Tournaments\Models;

use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Enums\MatchStatus;
use Database\Factories\GameMatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `Match` is a PHP reserved word, so this model is named `GameMatch` while
 * mapping to the `matches` table.
 *
 * @property MatchStatus $status
 * @property int|null $score1
 * @property int|null $score2
 * @property int|null $winner_entry_id
 * @property Carbon|null $scheduled_at
 * @property int $lock_version
 * @property array<string, mixed>|null $discord_channels
 * @property array<string, mixed>|null $voice_channels
 */
class GameMatch extends Model
{
    /** @use HasFactory<GameMatchFactory> */
    use HasFactory;

    protected $table = 'matches';

    // status/score1/score2/winner_entry_id/lock_version are result/state
    // fields, set only via Actions (later tasks), never client-fillable.
    // server_link_id is likewise provisioning state, set only by the
    // provisioning Job (Task 4), never client-fillable.
    protected $fillable = [
        'tournament_id',
        'round',
        'bracket',
        'position',
        'entry1_id',
        'entry2_id',
        'scheduled_at',
        'next_match_id',
        'next_slot',
        'loser_match_id',
        'loser_slot',
        'discord_channels',
        'voice_channels',
    ];

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'scheduled_at' => 'datetime',
            'discord_channels' => 'array',
            'voice_channels' => 'array',
        ];
    }

    /** @return BelongsTo<Tournament, $this> */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /** @return BelongsTo<TournamentEntry, $this> */
    public function entry1(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'entry1_id');
    }

    /** @return BelongsTo<TournamentEntry, $this> */
    public function entry2(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'entry2_id');
    }

    /** @return BelongsTo<TournamentEntry, $this> */
    public function winnerEntry(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'winner_entry_id');
    }

    /** @return BelongsTo<GameMatch, $this> */
    public function nextMatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_match_id');
    }

    /** @return BelongsTo<GameMatch, $this> */
    public function loserMatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'loser_match_id');
    }

    /** @return HasMany<MatchReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(MatchReport::class, 'match_id');
    }

    /** @return BelongsTo<ServerLink, $this> */
    public function serverLink(): BelongsTo
    {
        return $this->belongsTo(ServerLink::class);
    }

    protected static function newFactory(): GameMatchFactory
    {
        return GameMatchFactory::new();
    }
}
