<?php

namespace Database\Seeders;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Support\RepositoryEggs;
use Illuminate\Database\Seeder;

/**
 * Seeds a small, realistic games catalog.
 *
 * `pelican_egg_id` is deliberately left null here: numeric egg ids differ per
 * panel, presets reference their egg by uuid instead. `default_server_config` is
 * left empty.
 */
class GamesSeeder extends Seeder
{
    public function run(): void
    {
        $games = [
            ['name' => 'Counter-Strike 2', 'slug' => 'counter-strike-2', 'min_team_size' => 5, 'max_team_size' => 5],
            ['name' => 'Minecraft', 'slug' => 'minecraft', 'min_team_size' => 1, 'max_team_size' => 1],
            ['name' => 'Rocket League', 'slug' => 'rocket-league', 'min_team_size' => 3, 'max_team_size' => 3],
            ['name' => 'League of Legends', 'slug' => 'league-of-legends', 'min_team_size' => 5, 'max_team_size' => 5],
            ['name' => "Garry's Mod", 'slug' => 'garrys-mod', 'min_team_size' => 1, 'max_team_size' => 1],
        ];

        foreach ($games as $game) {
            Game::query()->firstOrCreate(
                ['slug' => $game['slug']],
                [
                    'name' => $game['name'],
                    'min_team_size' => $game['min_team_size'],
                    'max_team_size' => $game['max_team_size'],
                    'pelican_egg_id' => null,
                ],
            );
        }

        $this->seedPresets();
    }

    /**
     * Worked server presets (docs/infra/custom-game-servers-gmod-ttt.md): TTT runs
     * on the LANoMAT Garrys Mod egg; Prop Hunt stays locked until a Steam Web API
     * key is configured; CS2 GunGame is a documented stub. Existing presets of a
     * game are never touched, so orga edits survive a re-seed.
     */
    private function seedPresets(): void
    {
        $this->addPreset('garrys-mod', $this->ttt());
        $this->addPreset('garrys-mod', $this->propHunt());
        $this->addPreset('counter-strike-2', $this->cs2GunGame());
    }

    private function addPreset(string $slug, ServerPreset $preset): void
    {
        $game = Game::query()->where('slug', $slug)->first();

        if ($game === null || $game->findPreset($preset->key) !== null) {
            return;
        }

        $game->server_presets = [...$game->server_presets, $preset];
        $game->save();
    }

    private function ttt(): ServerPreset
    {
        return new ServerPreset(
            key: 'ttt',
            name: 'TTT (Trouble in Terrorist Town)',
            description: 'Rundenbasiertes Verräterspiel für bis zu 16 Spieler. Das Grundspiel bringt keine TTT-Karten mit: für echte TTT-Karten eine Workshop-Sammlung eintragen (braucht den Steam-Web-API-Schlüssel) oder ein Karten-Paket hochladen.',
            config: new ServerConfig(
                maxPlayers: 16,
                memoryMb: 3072,
                diskMb: 20480,
                cpuPercent: 200,
                environment: ['GAMEMODE' => 'terrortown', 'SRCDS_MAP' => 'gm_construct', 'TICKRATE' => '66', 'LUA_REFRESH' => '0', 'WORKSHOP_ID' => ''],
                overridable: ['SRCDS_MAP', 'MAX_PLAYERS', 'WORKSHOP_ID'],
                readinessType: 'a2s',
                readinessGame: 'Trouble in Terrorist Town',
                readinessFolder: 'garrysmod',
                readinessTimeout: 240,
                connectTemplate: 'steam://connect/{ip}:{port}',
                contentType: 'none',
                installMarker: 'garrysmod',
            ),
            eggUuid: RepositoryEggs::GMOD_UUID,
            eggName: 'Garrys Mod (LANoMAT)',
        );
    }

    private function propHunt(): ServerPreset
    {
        return new ServerPreset(
            key: 'prop-hunt',
            name: 'Prop Hunt (Workshop)',
            description: 'Versteckspiel mit Workshop-Gamemode. Gesperrt, bis ein Steam-Web-API-Schlüssel (PELICAN_STEAM_WEB_API_KEY) konfiguriert und eine Workshop-Sammlung mit Gamemode und Karten eingetragen ist. Gamemode-Ordnername und Karte nach dem ersten Start prüfen.',
            config: new ServerConfig(
                maxPlayers: 24,
                memoryMb: 4096,
                diskMb: 20480,
                cpuPercent: 200,
                environment: ['GAMEMODE' => 'prop_hunt', 'SRCDS_MAP' => 'ph_skyscraper_construct', 'TICKRATE' => '33', 'LUA_REFRESH' => '0'],
                overridable: ['GAMEMODE', 'SRCDS_MAP', 'MAX_PLAYERS'],
                readinessType: 'a2s',
                readinessGame: 'Prop Hunt',
                readinessFolder: 'garrysmod',
                readinessTimeout: 300,
                connectTemplate: 'steam://connect/{ip}:{port}',
                contentType: 'workshop',
                installMarker: 'garrysmod',
            ),
            eggUuid: RepositoryEggs::GMOD_UUID,
            eggName: 'Garrys Mod (LANoMAT)',
            requires: ['authkey', 'content'],
        );
    }

    private function cs2GunGame(): ServerPreset
    {
        return new ServerPreset(
            key: 'cs2-gungame',
            name: 'CS2 GunGame (Platzhalter)',
            description: 'Noch nicht startbar. Braucht Metamod:Source und CounterStrikeSharp mit einem GunGame-Plugin als Inhaltspaket (nach der Installation einzuspielen, `gameinfo.gi` muss nach jedem Spiel-Update erneut gepatcht werden) und ein Game Server Login Token (PELICAN_GSLT). Aktiv schalten, sobald beides bereitsteht.',
            config: new ServerConfig(
                maxPlayers: 16,
                memoryMb: 8192,
                diskMb: 61440,
                cpuPercent: 400,
                environment: ['GAME_TYPE' => '0', 'GAME_MODE' => '1', 'SRCDS_MAP' => 'de_dust2', 'RCON_ENABLED' => '0', 'VAC_ENABLED' => '1'],
                overridable: ['SRCDS_MAP', 'MAX_PLAYERS'],
                extraPorts: ['TV_PORT'],
                readinessType: 'a2s',
                readinessGame: 'Counter-Strike 2',
                readinessTimeout: 600,
                connectTemplate: 'steam://connect/{ip}:{port}',
                contentType: 'upload',
                installMarker: 'game/bin/linuxsteamrt64/cs2',
            ),
            eggName: 'Counter-Strike 2',
            enabled: false,
            requires: ['content', 'gslt'],
        );
    }
}
