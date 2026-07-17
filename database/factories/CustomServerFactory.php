<?php

namespace Database\Factories;

use App\Modules\CustomServers\Enums\CustomServerStatus;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomServer>
 */
class CustomServerFactory extends Factory
{
    protected $model = CustomServer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'-server',
            'remote_host_id' => RemoteHost::factory(),
            'event_id' => null,
            'image' => 'itzg/minecraft-server',
            'command' => null,
            'ports' => '25565:25565',
            'env' => ['EULA' => 'TRUE'],
            'container_name' => fake()->unique()->domainWord().'-container',
            'status' => CustomServerStatus::Stopped,
            'last_output' => null,
        ];
    }
}
