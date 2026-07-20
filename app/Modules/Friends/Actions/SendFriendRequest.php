<?php

declare(strict_types=1);

namespace App\Modules\Friends\Actions;

use App\Models\User;
use App\Modules\Friends\Enums\FriendshipStatus;
use App\Modules\Friends\Exceptions\FriendshipException;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Support\FriendService;
use Illuminate\Support\Facades\DB;

class SendFriendRequest
{
    public function __construct(private readonly FriendService $friends) {}

    public function handle(User $requester, User $addressee): Friendship
    {
        if ($requester->id === $addressee->id) {
            throw FriendshipException::cannotFriendSelf();
        }

        if ($this->friends->areFriends($requester, $addressee)) {
            throw FriendshipException::alreadyFriends();
        }

        if ($this->friends->blockedEitherWay($requester, $addressee)) {
            throw FriendshipException::blocked();
        }

        return DB::transaction(function () use ($requester, $addressee): Friendship {
            $pending = $this->friends->pendingBetween($requester, $addressee);

            if ($pending !== null) {
                // The only pending row this action ever creates is one where
                // requester/addressee match the two given users, so the
                // reverse case is the one where the addressee already
                // requested the requester — auto-accept it instead of
                // creating a duplicate.
                if ($pending->requester_id === $addressee->id) {
                    $pending->status = FriendshipStatus::Accepted;
                    $pending->save();

                    return $pending;
                }

                throw FriendshipException::requestPending();
            }

            return Friendship::query()->create([
                'requester_id' => $requester->id,
                'addressee_id' => $addressee->id,
                'status' => FriendshipStatus::Pending,
            ]);
        });
    }
}
