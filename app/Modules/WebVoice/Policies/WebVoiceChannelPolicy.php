<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Policies;

use App\Models\User;
use App\Modules\WebVoice\Models\WebVoiceChannel;

/**
 * Who may do what with a channel. Admins pass everything through the app-wide
 * `Gate::before`, so what is written here is the rule for everybody else:
 *
 * - a general channel is open to every logged-in user,
 * - a match channel only to the roster snapshot taken when the room was made,
 * - an archived channel to nobody (the actions refuse it for admins too),
 * - moderation and channel management to admins only, which means: never through this class.
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

    /** Delete a message, mute or remove a participant. Admin only (via Gate::before). */
    public function moderate(User $user, WebVoiceChannel $channel): bool
    {
        return false;
    }

    /** Create, rename, archive general channels. Admin only (via Gate::before). */
    public function manage(User $user): bool
    {
        return false;
    }
}
