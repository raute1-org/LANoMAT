<?php

namespace App\Modules\Games\Filament\Resources\Games\Pages;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Filament\Resources\Games\GameResource;
use App\Modules\Games\Models\Game;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditGame extends EditRecord
{
    protected static string $resource = GameResource::class;

    /**
     * The `default_server_config` cast reads back as a typed `ServerConfig`
     * value object (see ServerConfigCast), but the form fields are flat
     * scalars. Flatten it into the form's shape at this Filament-only
     * boundary, mirroring EditInfoscreenScene::mutateFormDataBeforeFill.
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

        unset($data['default_server_config']);

        return $data;
    }

    /**
     * `default_server_config` is deliberately not in Game::$fillable (see the
     * model's comment: it must never be reachable via uncontrolled raw mass
     * assignment). The admin Filament layer is already gated by
     * GamePolicy::update, so it is the one place allowed to set it
     * explicitly, going through ServerConfigCast exactly as any other write
     * would. Mirrors EditInfoscreenScene::handleRecordUpdate.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $config = CreateGame::extractConfig($data);

        $record->update($data);

        if ($record instanceof Game) {
            $record->default_server_config = $config;
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
