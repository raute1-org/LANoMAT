<?php

namespace Database\Factories;

use App\Modules\Provisioning\Models\Hypervisor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hypervisor>
 */
class HypervisorFactory extends Factory
{
    protected $model = Hypervisor::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'-pve',
            'base_url' => 'https://pve.example.test:8006',
            'node' => 'node1',
            'token_id' => 'lanomat@pve!provisioning',
            'token_secret' => '00000000-0000-4000-8000-000000000000',
            'tls_pin' => null,
            'jump_host_id' => null,
        ];
    }
}
