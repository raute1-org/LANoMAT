<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Models\User;
use App\Modules\WebVoice\Domain\ChannelArchived;
use App\Modules\WebVoice\Events\MessagePosted;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Models\WebVoiceMessage;
use App\Modules\WebVoice\Support\BroadcastsSafely;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Stores a plain-text message as the authenticated user and pushes it to the channel. */
class PostMessage
{
    use BroadcastsSafely;

    public function handle(User $author, WebVoiceChannel $channel, string $body): WebVoiceMessage
    {
        Gate::forUser($author)->authorize('post', $channel);

        if ($channel->isArchived()) {
            throw new ChannelArchived;
        }

        $body = trim($body);
        $max = (int) config('webvoice.chat.max_length');

        if ($body === '' || mb_strlen($body) > $max) {
            throw ValidationException::withMessages([
                'body' => __('web_voice.errors.body_length', ['max' => $max]),
            ]);
        }

        $message = new WebVoiceMessage;
        $message->web_voice_channel_id = $channel->getKey();
        $message->user_id = $author->getKey();
        $message->body = $body;
        $message->save();
        $message->setRelation('author', $author);

        $this->broadcastSafely(new MessagePosted($message));

        return $message;
    }
}
