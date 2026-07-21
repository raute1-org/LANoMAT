<?php

namespace App\Modules\Voting\Models;

use App\Models\User;
use Database\Factories\PollOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PollOption extends Model
{
    /** @use HasFactory<PollOptionFactory> */
    use HasFactory;

    // subject_user_id deliberately NOT fillable — it identifies the
    // participant an MVP-poll option represents (a badge-attribution
    // linkage), so it is only ever set via `forceFill()` in
    // {@see \App\Modules\Voting\Actions\SeedMvpPoll}. Standard poll options
    // leave it null.
    protected $fillable = [
        'poll_id',
        'label',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /** @return BelongsTo<User, $this> */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    public function tally(): int
    {
        return $this->votes()->count();
    }

    protected static function newFactory(): PollOptionFactory
    {
        return PollOptionFactory::new();
    }
}
