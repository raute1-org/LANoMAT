<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Models\User;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Support\Facades\Gate;

/** Moderation: disconnects someone from the room. Not a ban, they may join again. */
class RemoveParticipant
{
    public function __construct(private readonly WebVoiceClient $client) {}

    /** @throws WebVoiceUnavailable */
    public function handle(User $moderator, WebVoiceChannel $channel, User $target): void
    {
        Gate::forUser($moderator)->authorize('moderate', $channel);

        $this->client->removeParticipant($channel->roomName(), 'u'.$target->getKey());
    }
}
