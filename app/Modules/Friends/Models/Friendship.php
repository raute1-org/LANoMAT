<?php

declare(strict_types=1);

namespace App\Modules\Friends\Models;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use Database\Factories\FriendshipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A directed friendship row: `requester_id` sent the request to
 * `addressee_id`. One row per ordered pair (see the migration's unique
 * index) — the reverse-duplicate check (b,a already exists when a,b is
 * requested) is an action-layer concern, not a DB constraint.
 *
 * @property int $id
 * @property int $requester_id
 * @property int $addressee_id
 * @property FriendshipStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Friendship extends Model
{
    /** @use HasFactory<FriendshipFactory> */
    use HasFactory;

    protected $fillable = [
        'requester_id',
        'addressee_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => FriendshipStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    /**
     * Matches a friendship row between the two given users, regardless of
     * which one is the requester and which is the addressee.
     *
     * @param  Builder<Friendship>  $query
     * @return Builder<Friendship>
     */
    public function scopeBetweenUsers(Builder $query, int $a, int $b): Builder
    {
        return $query->where(function (Builder $query) use ($a, $b): void {
            $query->where(function (Builder $query) use ($a, $b): void {
                $query->where('requester_id', $a)->where('addressee_id', $b);
            })->orWhere(function (Builder $query) use ($a, $b): void {
                $query->where('requester_id', $b)->where('addressee_id', $a);
            });
        });
    }

    /**
     * The user on the other side of this friendship from `$me`. The FKs are
     * non-nullable (see the migration), so the relation always resolves —
     * the null-check only satisfies static analysis, it cannot happen in
     * practice as long as the referenced user row exists.
     */
    public function otherUser(User $me): User
    {
        $other = $this->requester_id === $me->id ? $this->addressee : $this->requester;

        if ($other === null) {
            throw new RuntimeException('Friendship references a missing user.');
        }

        return $other;
    }

    protected static function newFactory(): FriendshipFactory
    {
        return FriendshipFactory::new();
    }
}
