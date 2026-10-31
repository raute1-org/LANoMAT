<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Actions;

use App\Models\User;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Support\Facades\Gate;

/**
 * Tokens for the in-app latency self-test: the page opens two connections (one publishes a
 * synthetic tone, the other receives it) and measures the delay of the whole path without
 * microphone or speaker hardware.
 *
 * - The room is reserved per user: `<prefix>latency-u<id>`. The name is not numeric, so it can
 *   never equal a channel room (`<prefix><channel id>`), and no `web_voice_channels` row exists
 *   for it, so it appears in no channel list, no Filament table and no moderation call.
 * - The two connections need different identities: LiveKit disconnects the older connection when
 *   the same identity joins a room twice. They are `u<id>-probe-tx` (may publish) and
 *   `u<id>-probe-rx` (listen only); neither matches the `^u\d+$` pattern of real participants.
 * - Nothing is client-supplied; orga and admin only (policy `latencyTest`).
 */
class IssueLatencyTestTokens
{
    public function __construct(private readonly WebVoiceClient $client) {}

    /**
     * @return array{url: string, room: string, sender: array{token: string, identity: string}, receiver: array{token: string, identity: string}, audio: array<string, mixed>}
     *
     * @throws WebVoiceUnavailable when the SFU is not configured
     */
    public function handle(User $user): array
    {
        Gate::forUser($user)->authorize('latencyTest', WebVoiceChannel::class);

        $url = (string) config('webvoice.url');

        if ($url === '') {
            throw new WebVoiceUnavailable('LIVEKIT_URL is not configured.');
        }

        $room = (string) config('webvoice.room_prefix').'latency-u'.$user->getKey();
        $name = $user->name.' (Latenztest)';
        $senderIdentity = 'u'.$user->getKey().'-probe-tx';
        $receiverIdentity = 'u'.$user->getKey().'-probe-rx';

        return [
            'url' => $url,
            'room' => $room,
            'sender' => [
                'token' => $this->client->issueToken($room, $senderIdentity, $name, true),
                'identity' => $senderIdentity,
            ],
            'receiver' => [
                'token' => $this->client->issueToken($room, $receiverIdentity, $name, false),
                'identity' => $receiverIdentity,
            ],
            'audio' => (array) config('webvoice.audio'),
        ];
    }
}
