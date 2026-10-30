<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PresetAvailability;
use App\Modules\GameServers\Support\ReadinessPlan;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Starts an event-level game server from a game's preset (the orga's
 * "Server aus Preset starten"): checks the preset is available and the overrides
 * are allowed, claims a {@see ServerLink} (event, preset key, readiness snapshot)
 * and creates the Pelican server through {@see ProvisionGameServer}. The
 * {@see PollServerStatusJob} then verifies the install, starts the readiness
 * check and flips the link to Ready.
 *
 * Authorization is checked here against the passed user, so every caller (the
 * Filament action, a console command, a test) is covered by the policy.
 */
final class StartServerFromPreset
{
    public function __construct(private readonly PelicanClient $client) {}

    /**
     * @param  array<string, string>  $overrides  Environment overrides; only keys the preset lists as overridable.
     *
     * @throws GameServerException
     */
    public function handle(Event $event, Game $game, string $presetKey, ?string $title, array $overrides, User $requester): ServerLink
    {
        Gate::forUser($requester)->authorize('start', ServerLink::class);

        $preset = $game->findPreset($presetKey) ?? throw GameServerException::presetNotFound($presetKey);

        PresetAvailability::assertAvailable($preset);

        $config = $this->configWithOverrides($preset, $overrides);
        $title = $title !== null && trim($title) !== '' ? trim($title) : "{$event->name} {$preset->name}";

        $link = new ServerLink([
            'event_id' => $event->id,
            'game_id' => $game->id,
            'title' => $title,
            'preset_key' => $preset->key,
            'requested_by' => $requester->id,
        ]);
        $link->status = ServerLinkStatus::Provisioning;
        $link->readiness = ReadinessPlan::snapshot($preset->config);
        $link->save();

        try {
            $server = (new ProvisionGameServer($this->client))->handle(
                $game,
                $config,
                "lanomat-{$game->slug}-{$preset->key}-{$link->id}",
                $preset,
                $title,
                $requester,
                excludingLinkId: $link->id,
            );
        } catch (Throwable $e) {
            $link->status = ServerLinkStatus::Failed;
            $link->save();

            throw $e;
        }

        $link->pelican_server_id = $server->id;
        $link->pelican_server_identifier = $server->identifier;
        $link->save();

        PollServerStatusJob::dispatch($link->id)->delay(now()->addSeconds(10));

        return $link;
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     *
     * @throws GameServerException when an override targets a key the preset keeps fixed
     */
    private function configWithOverrides(ServerPreset $preset, array $overrides): array
    {
        $config = $preset->config->toArray();
        $environment = is_array($config['environment'] ?? null) ? $config['environment'] : [];

        foreach ($overrides as $key => $value) {
            if (! in_array((string) $key, $preset->config->overridable, true)) {
                throw GameServerException::overrideNotAllowed((string) $key);
            }

            $environment[(string) $key] = $value;
        }

        if ($environment !== []) {
            $config['environment'] = $environment;
        }

        return $config;
    }
}
