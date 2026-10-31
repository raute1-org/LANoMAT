<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Models\User;
use App\Modules\WebVoice\Events\MessageDeleted;
use App\Modules\WebVoice\Models\WebVoiceMessage;
use App\Modules\WebVoice\Support\BroadcastsSafely;
use Illuminate\Support\Facades\Gate;

/** Moderation: removes one message for everybody. Orga and admin (policy `moderate`). */
class DeleteMessage
{
    use BroadcastsSafely;

    public function handle(User $moderator, WebVoiceMessage $message): void
    {
        $channel = $message->channel()->firstOrFail();

        Gate::forUser($moderator)->authorize('moderate', $channel);

        $channelId = $channel->getKey();
        $messageId = $message->getKey();
        $message->delete();

        $this->broadcastSafely(new MessageDeleted($channelId, $messageId));
    }
}
