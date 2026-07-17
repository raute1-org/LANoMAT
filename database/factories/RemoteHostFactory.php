<?php

namespace Database\Factories;

use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Enums\HostStatus;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemoteHost>
 */
class RemoteHostFactory extends Factory
{
    protected $model = RemoteHost::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'-host',
            'hostname' => fake()->ipv4(),
            'ssh_port' => 22,
            'ssh_user' => 'lanomat',
            // Factories bypass $fillable, so ssh_private_key can be set
            // directly here (mirrors GameFactory's default_server_config).
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nFAKEKEYMATERIAL\n-----END OPENSSH PRIVATE KEY-----",
            'host_fingerprint' => null,
            'role' => HostRole::Generic,
            'event_id' => null,
            'status' => HostStatus::Unknown,
            'last_probed_at' => null,
        ];
    }
}
