<?php

declare(strict_types=1);

namespace App\Modules\Friends\Enums;

enum FriendshipStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
}
