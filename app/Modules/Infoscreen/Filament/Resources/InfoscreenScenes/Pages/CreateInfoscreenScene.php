<?php

namespace App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages;

use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\InfoscreenSceneResource;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInfoscreenScene extends CreateRecord
{
    protected static string $resource = InfoscreenSceneResource::class;

    /**
     * `config` is deliberately not in InfoscreenScene::$fillable (see the
     * model's comment), so it cannot be set via the default mass-assignment
     * constructor. The admin Filament layer is already gated by
     * InfoscreenScenePolicy::create, so it explicitly assigns config
     * afterwards, going through SceneConfigCast exactly as any other write
     * would. Mirrors Catering's CreateFoodOrder::handleRecordCreation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $config = self::extractConfig($data);

        /** @var InfoscreenScene $record */
        $record = new InfoscreenScene($data);
        $record->config = $config;
        $record->save();

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function extractConfig(array &$data): SceneConfig
    {
        $sponsorLogoPaths = $data['sponsorLogoPaths'] ?? [];

        $config = new SceneConfig(
            tournamentId: isset($data['tournamentId']) ? (int) $data['tournamentId'] : null,
            headline: $data['headline'] ?? null,
            body: $data['body'] ?? null,
            qrPayload: $data['qrPayload'] ?? null,
            qrCaption: $data['qrCaption'] ?? null,
            sponsorLogoPaths: is_array($sponsorLogoPaths) ? array_values($sponsorLogoPaths) : [],
        );

        unset(
            $data['headline'],
            $data['body'],
            $data['tournamentId'],
            $data['qrPayload'],
            $data['qrCaption'],
            $data['sponsorLogoPaths'],
        );

        return $config;
    }
}
