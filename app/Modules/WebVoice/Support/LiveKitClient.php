<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\RoomParticipant;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * {@see WebVoiceClient} for a self-hosted LiveKit server.
 *
 * Join tokens are HS256 JWTs signed locally (no network): `iss` = API key, `sub` = identity,
 * `video` = the grant. Server API calls (`/twirp/livekit.RoomService/<Method>`, JSON body) carry a
 * second, 60-second token with `roomAdmin` for that one room. Verified against the official docs
 * (access tokens, video grant, RoomService) with ctx7 on 2026-10-31; not yet run against a real server.
 */
class LiveKitClient implements WebVoiceClient
{
    private const SERVICE = '/twirp/livekit.RoomService/';

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $tokenTtl,
        private readonly int $timeout,
    ) {}

    public function issueToken(string $room, string $identity, string $name, bool $canPublish): string
    {
        return $this->sign($identity, $this->tokenTtl, $name, [
            'roomJoin' => true,
            'room' => $room,
            'canSubscribe' => true,
            'canPublish' => $canPublish,
            'canPublishData' => false,
            // The JWT grant uses the lowercase source names (the Twirp body below uses the enum names).
            'canPublishSources' => ['microphone'],
            'canUpdateOwnMetadata' => false,
        ]);
    }

    public function participants(string $room): array
    {
        $response = $this->call('ListParticipants', $room, ['room' => $room], notFoundIsEmpty: true);

        if ($response === null) {
            return [];
        }

        $participants = [];

        foreach ((array) $response->json('participants', []) as $participant) {
            if (! is_array($participant) || ! is_string($participant['identity'] ?? null)) {
                continue;
            }

            $participants[] = new RoomParticipant(
                $participant['identity'],
                (string) ($participant['name'] ?? ''),
                // proto3 JSON omits false values, so a missing flag means "false" only when the
                // permission object is present at all.
                (bool) (($participant['permission'] ?? ['canPublish' => true])['canPublish'] ?? false),
            );
        }

        return $participants;
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->call('RemoveParticipant', $room, ['room' => $room, 'identity' => $identity]);
    }

    public function setCanPublish(string $room, string $identity, bool $canPublish): void
    {
        // UpdateParticipant replaces the whole permission object, so every field is sent: omitting
        // canSubscribe would silently deafen the participant too.
        $this->call('UpdateParticipant', $room, [
            'room' => $room,
            'identity' => $identity,
            'permission' => [
                'canSubscribe' => true,
                'canPublish' => $canPublish,
                'canPublishData' => false,
                'canPublishSources' => ['MICROPHONE'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function call(string $method, string $room, array $body, bool $notFoundIsEmpty = false): ?Response
    {
        $token = $this->sign('lanomat-server', 60, null, ['roomAdmin' => true, 'room' => $room]);

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->withToken($token)
                ->acceptJson()
                ->post(rtrim($this->apiUrl, '/').self::SERVICE.$method, $body);
        } catch (ConnectionException $e) {
            throw new WebVoiceUnavailable("LiveKit unreachable: {$e->getMessage()}", previous: $e);
        }

        if ($notFoundIsEmpty && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new WebVoiceUnavailable("LiveKit {$method} failed with HTTP {$response->status()}");
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $video
     */
    private function sign(string $subject, int $ttl, ?string $name, array $video): string
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new WebVoiceUnavailable('LiveKit API key or secret is not configured.');
        }

        $now = time();
        $claims = [
            'iss' => $this->apiKey,
            'sub' => $subject,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'video' => $video,
        ];

        if ($name !== null) {
            $claims['name'] = $name;
        }

        try {
            return JWT::encode($claims, $this->apiSecret, 'HS256');
        } catch (Throwable $e) {
            throw new WebVoiceUnavailable("Could not sign a LiveKit token: {$e->getMessage()}", previous: $e);
        }
    }
}
