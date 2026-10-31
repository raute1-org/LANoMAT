<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Models\User;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\ChannelArchived;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Support\Facades\Gate;

/**
 * Mints the LiveKit join token for one viewer and one channel. The identity and display name
 * come from the authenticated user, the room from the channel row; nothing in the token is
 * client-supplied. A moderator mute is carried into the token (no publish right).
 */
class IssueJoinToken
{
    public function __construct(private readonly WebVoiceClient $client) {}

    /**
     * @return array{url: string, token: string, identity: string, muted: bool, audio: array<string, mixed>}
     *
     * @throws WebVoiceUnavailable when the SFU is not configured
     */
    public function handle(User $user, WebVoiceChannel $channel): array
    {
        Gate::forUser($user)->authorize('join', $channel);

        if ($channel->isArchived()) {
            throw new ChannelArchived;
        }

        $url = (string) config('webvoice.url');

        if ($url === '') {
            throw new WebVoiceUnavailable('LIVEKIT_URL is not configured.');
        }

        $muted = $channel->mutes()->where('user_id', $user->getKey())->exists();
        $identity = 'u'.$user->getKey();

        return [
            'url' => $url,
            'token' => $this->client->issueToken($channel->roomName(), $identity, $user->name, ! $muted),
            'identity' => $identity,
            'muted' => $muted,
            'audio' => (array) config('webvoice.audio'),
        ];
    }
}
