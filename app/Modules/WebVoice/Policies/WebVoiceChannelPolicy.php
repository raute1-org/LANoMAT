<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Policies;

use App\Models\User;
use App\Modules\WebVoice\Models\WebVoiceChannel;

/**
 * Who may do what with a channel. Admins pass everything through the app-wide `Gate::before`; the
 * rules below are written out so that orga are covered too (roles are global, there is no per-event orga):
 *
 * - a general channel is open to every logged-in user,
 * - a match channel to the roster snapshot taken when the room was made, and to orga (and admins),
 * - an archived channel to nobody (the actions refuse it for admins and orga too),
 * - moderation (delete a message, mute, remove) and seeing live rooms to orga and admins,
 * - creating, renaming and archiving general channels to admins only.
 * Helpers and participants are normal users.
 */
class WebVoiceChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WebVoiceChannel $channel): bool
    {
        if ($channel->isArchived()) {
            return false;
        }

        return $channel->isGeneral()
            || $user->isOrga()
            || $channel->members()->whereKey($user->getKey())->exists();
    }

    public function join(User $user, WebVoiceChannel $channel): bool
    {
        return $this->view($user, $channel);
    }

    public function post(User $user, WebVoiceChannel $channel): bool
    {
        return $this->view($user, $channel);
    }

    /** Delete a message, mute or remove a participant. Orga and admin. */
    public function moderate(User $user, WebVoiceChannel $channel): bool
    {
        return $user->isOrga();
    }

    /** See the live rooms in the admin panel. Orga and admin. */
    public function viewLive(User $user): bool
    {
        return $user->isOrga();
    }

    /** Create, rename, archive and restore general channels. Admin only. */
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }
}
