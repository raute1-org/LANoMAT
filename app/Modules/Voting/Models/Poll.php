<?php

namespace App\Modules\Voting\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Voting\Enums\PollKind;
use App\Modules\Voting\Enums\PollStatus;
use Database\Factories\PollFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property PollStatus $status
 * @property PollKind $kind
 * @property Carbon|null $closes_at
 */
class Poll extends Model
{
    /** @use HasFactory<PollFactory> */
    use HasFactory;

    // status/kind deliberately NOT fillable (privilege/state fields — set
    // only via actions, e.g. `forceFill` in SeedMvpPoll for `kind`).
    protected $fillable = [
        'event_id',
        'question',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PollStatus::class,
            'kind' => PollKind::class,
            'closes_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<PollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class);
    }

    /** @return HasMany<PollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    public function isOpenNow(): bool
    {
        if ($this->status !== PollStatus::Open) {
            return false;
        }

        return $this->closes_at === null || now()->lte($this->closes_at);
    }

    protected static function newFactory(): PollFactory
    {
        return PollFactory::new();
    }
}
