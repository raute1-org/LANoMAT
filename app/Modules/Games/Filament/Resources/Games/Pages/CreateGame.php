<?php

namespace App\Modules\Games\Filament\Resources\Games\Pages;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Filament\Resources\Games\GameResource;
use App\Modules\Games\Models\Game;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CreateGame extends CreateRecord
{
    protected static string $resource = GameResource::class;

    /**
     * `default_server_config` and `server_presets` are deliberately not in
     * Game::$fillable (see the model's comment), so neither can be set via
     * the default mass-assignment constructor. The admin Filament layer is
     * already gated by GamePolicy::create, so it explicitly assigns both
     * afterwards, going through their typed casts exactly as any other
     * write would. Mirrors CreateInfoscreenScene::handleRecordCreation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $config = self::extractConfig($data);
        $presets = self::extractPresets($data);

        /** @var Game $record */
        $record = new Game($data);
        $record->default_server_config = $config;
        $record->server_presets = $presets;
        $record->save();

        return $record;
    }

    /**
     * Extracts the game's default config from the form data — either the
     * settings-form fields (form mode) or the uploaded config file (upload
     * mode, `default_config_mode` === 'upload'), never both: the Radio in
     * GameForm keeps them mutually exclusive in the UI, and this reads
     * whichever one is active. `default_config_mode` itself is
     * `dehydrated(false)` (UI-only), so it never reaches `$data` here.
     *
     * Parses the uploaded file itself (rather than delegating to
     * GameServers\Support\EffectiveConfig::parseUploadedConfig) to keep this
     * Games-module Filament page from depending on the GameServers module —
     * GameServers already depends on Games (e.g. GameServerException,
     * ProvisionMatchServerJob reading Game::default_server_config), and the
     * modular-monolith rule (CLAUDE.md) never reaches back the other way.
     * The two are intentionally near-identical: both parse the same
     * ServerConfig-shaped JSON off the `public` disk.
     *
     * @param  array<string, mixed>  $data
     */
    public static function extractConfig(array &$data): ServerConfig
    {
        $uploadPath = $data['default_config_upload'] ?? null;

        $config = is_string($uploadPath) && $uploadPath !== ''
            ? self::parseUploadedConfig($uploadPath)
            : new ServerConfig(
                maxPlayers: isset($data['max_players']) ? (int) $data['max_players'] : null,
                map: $data['map'] ?? null,
                password: $data['password'] ?? null,
            );

        unset(
            $data['max_players'],
            $data['map'],
            $data['password'],
            $data['default_config_upload'],
        );

        return $config;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<ServerPreset>
     */
    public static function extractPresets(array &$data): array
    {
        $rows = $data['server_presets'] ?? [];
        unset($data['server_presets']);

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row): ServerPreset => new ServerPreset(
                key: (string) ($row['key'] ?? ''),
                name: (string) ($row['name'] ?? ''),
                config: new ServerConfig(
                    maxPlayers: isset($row['max_players']) ? (int) $row['max_players'] : null,
                    map: $row['map'] ?? null,
                    password: $row['password'] ?? null,
                ),
            ),
            $rows,
        ));
    }

    private static function parseUploadedConfig(string $path): ServerConfig
    {
        $contents = Storage::disk('public')->get($path);

        if ($contents === null) {
            return new ServerConfig;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? ServerConfig::fromArray($decoded) : new ServerConfig;
    }
}
