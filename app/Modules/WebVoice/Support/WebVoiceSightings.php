<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

use App\Modules\Presence\Contracts\VoiceSightingSource;
use App\Modules\Presence\Domain\VoiceSighting;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Models\WebVoiceChannel;

/**
 * Who is in which LANoMAT web voice room. The LiveKit identity is `u<user id>` (set by the server
 * when the join token was issued), so the match to a user is exact. One LiveKit call per live
 * room, which is why this runs from the scheduled refresh and never inside a request. If LiveKit
 * cannot be asked at all the exception goes up and the provider counts as unknown.
 */
class WebVoiceSightings implements VoiceSightingSource
{
    public function __construct(private readonly WebVoiceClient $client) {}

    public function provider(): string
    {
        return 'webvoice';
    }

    public function sightings(): array
    {
        $sightings = [];

        $channels = WebVoiceChannel::query()->whereNull('archived_at')->with('members:id')->get();

        foreach ($channels as $channel) {
            $audience = $channel->isGeneral() ? null : array_values($channel->members->pluck('id')->map(fn ($id): int => (int) $id)->all());

            foreach ($this->client->participants($channel->roomName()) as $participant) {
                $userId = $participant->userId();

                if ($userId === null) {
                    continue;
                }

                $sightings[] = new VoiceSighting(
                    provider: 'webvoice',
                    roomRef: (string) $channel->id,
                    roomLabel: $channel->name,
                    kind: match ($channel->kind) {
                        ChannelKind::General => VoiceSighting::KIND_GENERAL,
                        ChannelKind::Match => VoiceSighting::KIND_MATCH,
                        ChannelKind::Team => VoiceSighting::KIND_TEAM,
                    },
                    userId: $userId,
                    audience: $audience,
                );
            }
        }

        return $sightings;
    }
}
