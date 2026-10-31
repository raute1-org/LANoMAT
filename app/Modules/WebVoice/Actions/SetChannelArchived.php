<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Models\User;
use App\Modules\WebVoice\Events\ChannelsChanged;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Support\BroadcastsSafely;
use Illuminate\Support\Facades\Gate;

/**
 * Admin: hides a general channel from every list (archive) or brings it back (restore). People
 * already connected stay until they leave, but nobody can join an archived channel any more.
 */
class SetChannelArchived
{
    use BroadcastsSafely;

    public function handle(User $admin, WebVoiceChannel $channel, bool $archived): void
    {
        Gate::forUser($admin)->authorize('manage', WebVoiceChannel::class);

        $channel->forceFill(['archived_at' => $archived ? now() : null])->save();

        $this->broadcastSafely(new ChannelsChanged);
    }
}
