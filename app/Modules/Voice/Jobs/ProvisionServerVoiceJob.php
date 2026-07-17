<?php

declare(strict_types=1);

namespace App\Modules\Voice\Jobs;

use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Voice\Listeners\ProvisionServerVoiceOnReady;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Creates one SHARED per-match-server voice channel (both rosters together,
 * for in-game callouts once they're actually on the same server) on **every
 * active provider** ({@see VoiceProviders}), mirroring
 * {@see ProvisionMatchVoiceJob}'s per-provider fan-out but creating a single
 * channel per provider rather than one per roster — this is distinct from
 * (and additional to) the per-team match channels that job provisions.
 *
 * Dispatched by {@see ProvisionServerVoiceOnReady}
 * on the M6 {@see ServerLinkUpdated} event once the match's game server is
 * actually `Ready` (issue #13's "voice channel per running game server").
 *
 * Persisted onto `matches.voice_channels[<provider>]['server_channel_id']` —
 * the Voice module's existing per-match surface, kept deliberately separate
 * from GameServers' own tables per the module-boundary rule. Idempotency is
 * per provider, exactly like the sibling match-voice job: a provider whose
 * `server_channel_id` is already set is skipped, but a provider newly added
 * to the active set is still provisioned on a re-fired `ServerLinkUpdated`.
 */
class ProvisionServerVoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $matchId,
    ) {}

    public function handle(VoiceProviders $providers): void
    {
        $match = GameMatch::query()->with(['entry1', 'entry2'])->find($this->matchId);

        if ($match === null) {
            return;
        }

        $voiceChannels = $match->voice_channels ?? [];
        $channelName = self::channelNameFor($match);

        foreach ($providers->active() as $value => $client) {
            if (isset($voiceChannels[$value]['server_channel_id'])) {
                continue;
            }

            $channel = $client->createChannel($channelName, null, true);

            $voiceChannels[$value] = [
                ...($voiceChannels[$value] ?? []),
                'server_channel_id' => $channel->id,
            ];
        }

        $match->update(['voice_channels' => $voiceChannels]);
    }

    /**
     * "🎮 Team Alpha vs Team Bravo" when both rosters are known, falling back
     * to a plain match label (round/bracket) for the rare case a server
     * comes up before both entries are set — keeps the job usable without
     * requiring the caller to guard on entry presence.
     */
    private static function channelNameFor(GameMatch $match): string
    {
        if ($match->entry1 !== null && $match->entry2 !== null) {
            return "🎮 {$match->entry1->display_name} vs {$match->entry2->display_name}";
        }

        return "🎮 Match #{$match->id}";
    }
}
