<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Models\User;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\Models\WebVoiceMute;
use Illuminate\Support\Facades\Gate;

/**
 * Moderation: takes (or gives back) the right to speak in one channel. The decision is
 * stored first, so it also holds for the person's next token; the live push to the SFU is
 * best effort and reported as the return value.
 */
class MuteParticipant
{
    public function __construct(private readonly WebVoiceClient $client) {}

    /**
     * @return bool whether the change reached the SFU for someone connected right now
     */
    public function handle(User $moderator, WebVoiceChannel $channel, User $target, bool $muted = true): bool
    {
        Gate::forUser($moderator)->authorize('moderate', $channel);

        if ($muted) {
            $mute = WebVoiceMute::query()
                ->where('web_voice_channel_id', $channel->getKey())
                ->where('user_id', $target->getKey())
                ->first() ?? new WebVoiceMute;
            $mute->web_voice_channel_id = $channel->getKey();
            $mute->user_id = $target->getKey();
            $mute->muted_by = $moderator->getKey();
            $mute->save();
        } else {
            WebVoiceMute::query()
                ->where('web_voice_channel_id', $channel->getKey())
                ->where('user_id', $target->getKey())
                ->delete();
        }

        try {
            $this->client->setCanPublish($channel->roomName(), 'u'.$target->getKey(), ! $muted);
        } catch (WebVoiceUnavailable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
