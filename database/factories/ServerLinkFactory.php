<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ConsoleKind;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServerLink> */
class ServerLinkFactory extends Factory
{
    protected $model = ServerLink::class;

    public function definition(): array
    {
        return [
            'match_id' => null,
            'tournament_id' => null,
            'pelican_server_id' => null,
            'pelican_server_identifier' => null,
            // Factories bypass $fillable, so pelican_server_id/join_info/
            // status can be set directly here (mirrors GameFactory's
            // default_server_config).
            'join_info' => new JoinInfo,
            'status' => ServerLinkStatus::Pending,
            'manual' => false,
            'permanent' => false,
            'source' => ServerSource::Pelican,
        ];
    }

    /** A permanent server, by default a registered external one that answers. */
    public function permanent(ServerSource $source = ServerSource::External): static
    {
        return $this->state(fn (): array => [
            'permanent' => true,
            'source' => $source,
            'title' => 'Dauerserver',
            'status' => ServerLinkStatus::Ready,
            'join_info' => new JoinInfo(address: '203.0.113.10', port: 25565),
        ]);
    }

    /** A permanent external server adopted as a container on a managed remote host. */
    public function remoteDocker(?RemoteHost $host = null, string $container = 'game-container'): static
    {
        return $this->permanent(ServerSource::External)->state(fn (): array => [
            'remote_host_id' => ($host ?? RemoteHost::factory()->create())->id,
            'remote_container' => $container,
            'console_kind' => ConsoleKind::RconCli,
        ]);
    }
}
