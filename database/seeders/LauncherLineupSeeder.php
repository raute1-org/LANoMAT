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
 * server link; NFSU2 has no process LANoMAT controls, just the public
 * Underground 2 Revived page. This seeder registers that page once as a
 * permanent external server so `/servers` and the game page agree:
 * `NFSU2 (Browser)`, `nfsu2.xyz:443`, joined through `https://nfsu2.xyz/`.
 *
 * It then registers our own native NFSU2 server (NFS-Online) when the venue
 * configured its host — `NFS_SERVER_HOST` through `launcher.nfs.server_host`;
 * there is no built-in address. Without one it registers no native server and
 * says so on the console; an existing row is left alone either way.
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

        // Our own native NFSU2 server (NFS-Online). The venue sets its host;
        // without one there is no server to register, and guessing an address
        // is exactly what this seeder must not do.
        $nfsHost = trim((string) config('launcher.nfs.server_host'));

        if ($nfsHost === '') {
            // A server registered by an earlier run is the venue's own record:
            // it is neither changed nor removed here, and its address is what
            // NFSU2 install plans keep naming.
            $existing = ServerLink::query()
                ->where('title', 'NFSU2 (NFS-Online, unser Server)')
                ->where('permanent', true)
                ->first();

            $this->command?->warn($existing instanceof ServerLink
                ? 'NFS_SERVER_HOST is empty: the native NFSU2 (NFS-Online) server registered earlier was left as it is ('
                    .($existing->join_info->address ?? 'no address').'). Set NFS_SERVER_HOST in .env and run '
                    .'`php artisan db:seed --class=LauncherLineupSeeder --force` again to move it, or edit the server in the admin panel.'
                : 'NFS_SERVER_HOST is empty: no native NFSU2 (NFS-Online) server was registered. '
                    .'Set it in .env and run `php artisan db:seed --class=LauncherLineupSeeder --force` again to add one.');

            return;
        }

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
