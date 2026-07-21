<?php

declare(strict_types=1);

namespace App\Modules\News\Policies;

use App\Models\User;
use App\Modules\News\Models\NewsPost;

class NewsPostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrga();
    }

    public function view(User $user, NewsPost $post): bool
    {
        return $user->isOrga();
    }

    public function create(User $user): bool
    {
        return $user->isOrga();
    }

    public function update(User $user, NewsPost $post): bool
    {
        return $user->isOrga();
    }

    public function delete(User $user, NewsPost $post): bool
    {
        return $user->isOrga();
    }

    public function publish(User $user, NewsPost $post): bool
    {
        return $user->isOrga();
    }
}
