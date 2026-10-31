<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Events;

use App\Support\Broadcasting\RetriesBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A moderator removed a chat message; open clients drop it from view. */
class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable;
    use RetriesBroadcast;

    public function __construct(
        public readonly int $channelId,
        public readonly int $messageId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('web-voice.channel.'.$this->channelId);
    }

    /** @return array{channel_id: int, message_id: int} */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId, 'message_id' => $this->messageId];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }
}
