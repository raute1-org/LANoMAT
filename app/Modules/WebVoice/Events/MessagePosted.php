<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Events;

use App\Modules\WebVoice\Models\WebVoiceMessage;
use App\Support\Broadcasting\RetriesBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat message went into a channel. Private channel `web-voice.channel.{id}`, authorized
 * through the same policy as reading the channel. {@see ShouldBroadcastNow}: chat should be as
 * quick as the websocket, so there is no queue hop between the POST and the push.
 */
class MessagePosted implements ShouldBroadcastNow
{
    use Dispatchable;
    use RetriesBroadcast;

    public function __construct(public readonly WebVoiceMessage $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('web-voice.channel.'.$this->message->web_voice_channel_id);
    }

    /** @return array{message: array<string, mixed>} */
    public function broadcastWith(): array
    {
        return ['message' => $this->message->toPayload()];
    }

    public function broadcastAs(): string
    {
        return 'message.posted';
    }
}
