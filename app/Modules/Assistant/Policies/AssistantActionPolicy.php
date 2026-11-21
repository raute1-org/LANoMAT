<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Policies;

use App\Models\User;
use App\Modules\Assistant\Models\AssistantAction;

/** The audit log is read-only for orga and admin; nobody edits or deletes it through the panel. */
final class AssistantActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, AssistantAction $action): bool
    {
        return $user->isOrga();
    }
}
