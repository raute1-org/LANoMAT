<?php

namespace App\Modules\Discord\Http;

use App\Modules\Discord\Events\DiscordGuildMemberJoined;
use App\Modules\Discord\Events\DiscordGuildMemberLeft;
use App\Modules\Discord\Events\DiscordMessageCreated;
use App\Modules\Discord\Events\DiscordMessageReactionChanged;
use App\Modules\Discord\Interactions\CommandRouter;
use App\Modules\Discord\Interactions\InteractionResponseType;
use App\Modules\Discord\Jobs\SendFollowupJob;
use App\Modules\Discord\Support\HandleVoiceState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single ingress for gateway events forwarded by the discord.js sidecar
 * (docker/discord-gateway). The sidecar is pure transport; every decision is
 * made here in PHP. Routes by the envelope's `type`; the sidecar has already
 * `deferReply()`d interactions, so command content is delivered as a
 * follow-up (see SendFollowupJob) rather than an immediate response.
 */
class GatewayIngressController
{
    public function __invoke(Request $request): Response
    {
        $type = $request->string('type')->toString();
        /** @var array<string, mixed> $data */
        $data = (array) $request->input('data', []);

        match ($type) {
            'interaction' => $this->handleInteraction($data),
            'voice_state' => app(HandleVoiceState::class)->handle($data),
            'member_add' => DiscordGuildMemberJoined::dispatch((string) ($data['user_id'] ?? '')),
            'member_remove' => DiscordGuildMemberLeft::dispatch((string) ($data['user_id'] ?? '')),
            'message_create' => DiscordMessageCreated::dispatch((string) ($data['channel_id'] ?? ''), (string) ($data['author_id'] ?? ''), (string) ($data['message_id'] ?? '')),
            'reaction' => DiscordMessageReactionChanged::dispatch((string) ($data['message_id'] ?? ''), (string) ($data['channel_id'] ?? ''), (string) ($data['user_id'] ?? ''), (string) ($data['emoji'] ?? ''), (bool) ($data['added'] ?? false)),
            default => Log::info('discord.gateway.ignored', ['type' => $type]),
        };

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleInteraction(array $payload): void
    {
        $response = CommandRouter::dispatch($payload);

        // The sidecar already sent the deferred acknowledgement, so an
        // immediate (type 4) response becomes a follow-up edit. A deferred
        // (type 5) handler has already queued its own SendFollowupJob.
        if (($response['type'] ?? null) !== InteractionResponseType::ChannelMessageWithSource->value) {
            return;
        }

        $content = $response['data']['content'] ?? null;
        $applicationId = $payload['application_id'] ?? null;
        $token = $payload['token'] ?? null;

        if (is_string($content) && $content !== '' && is_string($applicationId) && is_string($token)) {
            Bus::dispatch(new SendFollowupJob($applicationId, $token, $content));
        }
    }
}
