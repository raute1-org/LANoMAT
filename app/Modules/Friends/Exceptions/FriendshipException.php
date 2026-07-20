<?php

declare(strict_types=1);

namespace App\Modules\Friends\Exceptions;

use DomainException;

class FriendshipException extends DomainException
{
    private function __construct(string $message, public readonly string $translationKey)
    {
        parent::__construct($message);
    }

    public static function cannotFriendSelf(): self
    {
        return new self('A user cannot send a friend request to themselves.', 'friends.errors.cannot_friend_self');
    }

    public static function alreadyFriends(): self
    {
        return new self('These users are already friends.', 'friends.errors.already_friends');
    }

    public static function requestPending(): self
    {
        return new self('A friend request is already pending between these users.', 'friends.errors.request_pending');
    }

    public static function blocked(): self
    {
        return new self('One of these users has blocked the other.', 'friends.errors.blocked');
    }
}
