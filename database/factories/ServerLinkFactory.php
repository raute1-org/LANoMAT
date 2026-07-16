<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
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
            // Factories bypass $fillable, so pelican_server_id/join_info/
            // status can be set directly here (mirrors GameFactory's
            // default_server_config).
            'join_info' => new JoinInfo,
            'status' => ServerLinkStatus::Pending,
            'manual' => false,
        ];
    }
}
