<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Queries;

use App\Models\User;
use App\Modules\WebVoice\Domain\ChannelArchived;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Models\WebVoiceMessage;
use Illuminate\Support\Facades\Gate;

/** One page of chat history, oldest first, for someone who may read the channel. */
class ChannelMessages
{
    /**
     * @return list<array<string, mixed>>
     */
    public function page(User $viewer, WebVoiceChannel $channel, ?int $beforeId = null): array
    {
        Gate::forUser($viewer)->authorize('view', $channel);

        if ($channel->isArchived()) {
            throw new ChannelArchived;
        }

        $query = WebVoiceMessage::query()
            ->with('author')
            ->where('web_voice_channel_id', $channel->getKey())
            ->orderByDesc('id')
            ->limit((int) config('webvoice.chat.history'));

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        return array_values($query->get()
            ->reverse()
            ->map(fn (WebVoiceMessage $message): array => $message->toPayload())
            ->all());
    }
}
