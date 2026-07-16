<?php

namespace App\Modules\Games\Filament\Resources\Games\Pages;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Filament\Resources\Games\GameResource;
use App\Modules\Games\Models\Game;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditGame extends EditRecord
{
    protected static string $resource = GameResource::class;

    /**
     * The `default_server_config`/`server_presets` casts read back as typed
     * value objects (see ServerConfigCast/ServerPresetsCast), but the form
     * fields are flat scalars/rows. Flatten both into the form's shape at
     * this Filament-only boundary, mirroring
     * EditInfoscreenScene::mutateFormDataBeforeFill.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $config = $data['default_server_config'] ?? null;

        if ($config instanceof ServerConfig) {
            $data['max_players'] = $config->maxPlayers;
            $data['map'] = $config->map;
            $data['password'] = $config->password;
        }

        $presets = $data['server_presets'] ?? [];

        if (is_array($presets)) {
            $data['server_presets'] = array_map(
                static fn (ServerPreset $preset): array => [
                    'key' => $preset->key,
                    'name' => $preset->name,
                    'max_players' => $preset->config->maxPlayers,
                    'map' => $preset->config->map,
                    'password' => $preset->config->password,
                ],
                $presets,
            );
        }

        unset($data['default_server_config']);

        return $data;
    }

    /**
     * `default_server_config` and `server_presets` are deliberately not in
     * Game::$fillable (see the model's comment: neither must be reachable
     * via uncontrolled raw mass assignment). The admin Filament layer is
     * already gated by GamePolicy::update, so it is the one place allowed to
     * set both explicitly, going through their typed casts exactly as any
     * other write would. Mirrors EditInfoscreenScene::handleRecordUpdate.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $config = CreateGame::extractConfig($data);
        $presets = CreateGame::extractPresets($data);

        $record->update($data);

        if ($record instanceof Game) {
            $record->default_server_config = $config;
            $record->server_presets = $presets;
            $record->save();
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete'),
        ];
    }
}
