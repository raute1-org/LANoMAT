<?php

namespace App\Modules\Voting\Models;

use App\Models\User;
use Database\Factories\PollVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollVote extends Model
{
    /** @use HasFactory<PollVoteFactory> */
    use HasFactory;

    // Deliberately NOT mass-assignable from request input: a vote must be
    // created only by the (Task 12) CastVote action, which sets poll_id,
    // poll_option_id, and user_id explicitly from the authenticated user
    // and a policy-checked poll/option pair — never from client-forged
    // array/JSON input. `user_id` is identity-defining and is intentionally
    // excluded from $fillable: it is set explicitly by the trusted CastVote
    // action (and by the factory, which force-fills and bypasses $fillable
    // entirely), never mass-assigned from request input.
    protected $fillable = [
        'poll_id',
        'poll_option_id',
    ];

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /** @return BelongsTo<PollOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'poll_option_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): PollVoteFactory
    {
        return PollVoteFactory::new();
    }
}
