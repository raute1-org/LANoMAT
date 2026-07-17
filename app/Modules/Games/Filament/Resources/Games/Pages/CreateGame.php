<?php

declare(strict_types=1);

namespace App\Modules\Games\Filament\Resources\Games\Pages;

use App\Modules\Games\Domain\InstallHint;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Filament\Resources\Games\GameResource;
use App\Modules\Games\Models\Game;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

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
        $installHint = self::extractInstallHint($data);

        /** @var Game $record */
        $record = new Game($data);
        $record->default_server_config = $config;
        $record->server_presets = $presets;
        $record->install_hint = $installHint;
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
     * Delegates the upload parse to {@see ServerConfig::fromStoragePath()} —
     * the single source of truth shared with
     * GameServers\Support\EffectiveConfig, so a corrupt/missing upload
     * throws consistently everywhere instead of silently resolving to an
     * empty config here and a hard error there. Filament's
     * CreateRecord/EditRecord lifecycle only swallows {@see Halt}
     * exceptions (not arbitrary ones), so the throw is caught here and
     * turned into a translated form error + Halt instead of an unhandled
     * 500.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Halt if the uploaded default config is missing or invalid.
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
     * @throws Halt if the uploaded default config is missing or invalid.
     */
    private static function parseUploadedConfig(string $path): ServerConfig
    {
        try {
            return ServerConfig::fromStoragePath($path);
        } catch (InvalidArgumentException|JsonException) {
            Notification::make()
                ->title(__('gameservers.errors.invalid_default_config_upload'))
                ->danger()
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
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

    /**
     * Extracts the game's install hint ("So kommst du ran", roadmap 7.5)
     * from the form's flat `install_hint_*` fields — install_hint itself is
     * not in Game::$fillable, so it must be assigned separately through its
     * typed cast, mirroring extractConfig()/extractPresets() above.
     *
     * @param  array<string, mixed>  $data
     */
    public static function extractInstallHint(array &$data): InstallHint
    {
        $hint = new InstallHint(
            steamUrl: $data['install_hint_steam_url'] ?? null,
            shareUrl: $data['install_hint_share_url'] ?? null,
            versionNote: $data['install_hint_version_note'] ?? null,
        );

        unset(
            $data['install_hint_steam_url'],
            $data['install_hint_share_url'],
            $data['install_hint_version_note'],
        );

        return $hint;
    }
}
