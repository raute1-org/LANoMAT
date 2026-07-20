<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Enums\LinkedAccountProvider;

class UnlinkAccount
{
    public function handle(User $user, LinkedAccountProvider $provider): void
    {
        $user->linkedAccount($provider)?->delete();
    }
}
