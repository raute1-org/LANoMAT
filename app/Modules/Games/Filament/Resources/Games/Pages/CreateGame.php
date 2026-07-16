<?php

namespace App\Modules\Games\Filament\Resources\Games\Pages;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Filament\Resources\Games\GameResource;
use App\Modules\Games\Models\Game;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGame extends CreateRecord
{
    protected static string $resource = GameResource::class;

    /**
     * `default_server_config` is deliberately not in Game::$fillable (see the
     * model's comment), so it cannot be set via the default mass-assignment
     * constructor. The admin Filament layer is already gated by
     * GamePolicy::create, so it explicitly assigns the config afterwards,
     * going through ServerConfigCast exactly as any other write would.
     * Mirrors CreateInfoscreenScene::handleRecordCreation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $config = self::extractConfig($data);

        /** @var Game $record */
        $record = new Game($data);
        $record->default_server_config = $config;
        $record->save();

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function extractConfig(array &$data): ServerConfig
    {
        $config = new ServerConfig(
            maxPlayers: isset($data['max_players']) ? (int) $data['max_players'] : null,
            map: $data['map'] ?? null,
            password: $data['password'] ?? null,
        );

        unset(
            $data['max_players'],
            $data['map'],
            $data['password'],
        );

        return $config;
    }
}
