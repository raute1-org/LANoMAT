<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\Voice\Listeners\ProvisionServerVoiceOnReady;
use App\Modules\Voice\Models\VoiceServerChannel;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Creates one voice channel for an EVENT-level game server (a preset server
 * that belongs to the event, not to a tournament match) on every active
 * provider, named after the server. Dispatched by
 * {@see ProvisionServerVoiceOnReady} on a Ready {@see ServerLinkUpdated}; the
 * name comes from the event payload so the Voice module never reads
 * GameServers' tables.
 *
 * The ids are kept in {@see VoiceServerChannel}, one row per provider. Idempotent
 * per provider: a provider that already has a row is skipped, one added later
 * is still provisioned. Removed again by {@see CleanupEventServerVoiceJob}.
 */
class ProvisionEventServerVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $serverLinkId,
        public readonly string $serverName,
    ) {}

    public function handle(VoiceProviders $providers): void
    {
        $name = '🎮 '.($this->serverName !== '' ? $this->serverName : 'Server #'.$this->serverLinkId);

        foreach ($providers->active() as $value => $client) {
            if (VoiceServerChannel::query()->where('server_link_id', $this->serverLinkId)->where('provider', $value)->exists()) {
                continue;
            }

            $channel = $client->createChannel($name, null, true);

            VoiceServerChannel::query()->create([
                'server_link_id' => $this->serverLinkId,
                'provider' => $value,
                'channel_id' => $channel->id,
            ]);
        }
    }
}
