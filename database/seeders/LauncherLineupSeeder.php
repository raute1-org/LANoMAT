<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Database\Seeder;

/**
 * The one manual browser server of the first lineup.
 *
 * The launcher lineup ships two games. UT2004 already has its permanent
 * `10.23.0.40:7777` link; NFSU2 has no process LANoMAT controls, just the
 * public Underground 2 Revived page. This seeder registers that page once as a
 * permanent external server so `/servers` and the game page agree:
 * `NFSU2 (Browser)`, `nfsu2.xyz:443`, joined through `https://nfsu2.xyz/`.
 *
 * Idempotent, and it never touches another server row. It is not called by
 * `DatabaseSeeder`: run it explicitly on a fresh installation
 * (`php artisan db:seed --class=LauncherLineupSeeder`).
 */
final class LauncherLineupSeeder extends Seeder
{
    public function run(): void
    {
        $game = Game::query()->updateOrCreate(
            ['slug' => 'need-for-speed-underground-2-browser'],
            [
                'name' => 'Need for Speed: Underground 2 (Browser)',
                'min_team_size' => 1,
                'max_team_size' => 6,
            ],
        );

        $link = ServerLink::query()->firstOrNew([
            'title' => 'NFSU2 (Browser)',
            'permanent' => true,
        ]);

        $link->forceFill([
            'game_id' => $game->id,
            'status' => ServerLinkStatus::Ready,
            'manual' => true,
            'permanent' => true,
            'source' => ServerSource::External,
            'query_protocol' => null,
            'join_info' => new JoinInfo(
                address: 'nfsu2.xyz',
                port: 443,
                connectString: 'https://nfsu2.xyz/',
            ),
        ])->save();

        // Our own native NFSU2 server (NFS-Online). The event address is
        // 10.23.0.12; 10.23.3.220 is the tested laptop fallback. Override it
        // with NFS_SERVER_HOST before seeding on the venue server.
        $nativeGame = Game::query()->updateOrCreate(
            ['slug' => 'need-for-speed-underground-2'],
            [
                'name' => 'Need for Speed: Underground 2',
                'min_team_size' => 1,
                'max_team_size' => 6,
            ],
        );

        $nativeLink = ServerLink::query()->firstOrNew([
            'title' => 'NFSU2 (NFS-Online, unser Server)',
            'permanent' => true,
        ]);

        $nfsHost = (string) config('launcher.nfs.server_host', '10.23.3.220');

        $nativeLink->forceFill([
            'game_id' => $nativeGame->id,
            'status' => ServerLinkStatus::Ready,
            'manual' => true,
            'permanent' => true,
            'source' => ServerSource::External,
            'query_protocol' => null,
            'join_info' => new JoinInfo(
                address: $nfsHost,
                port: 20922,
                connectString: $nfsHost,
            ),
        ])->save();
    }
}
