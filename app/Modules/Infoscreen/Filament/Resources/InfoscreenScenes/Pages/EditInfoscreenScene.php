<?php

namespace App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages;

use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\InfoscreenSceneResource;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditInfoscreenScene extends EditRecord
{
    protected static string $resource = InfoscreenSceneResource::class;

    /**
     * The `config` cast reads back as a typed `SceneConfig` value object
     * (see SceneConfigCast), but the form fields are flat scalars/Repeater
     * state. Flatten it into the form's shape at this Filament-only
     * boundary, mirroring EditFoodOrder::mutateFormDataBeforeFill.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $config = $data['config'] ?? null;

        if ($config instanceof SceneConfig) {
            $data['headline'] = $config->headline;
            $data['body'] = $config->body;
            $data['tournamentId'] = $config->tournamentId;
            $data['qrPayload'] = $config->qrPayload;
            $data['qrCaption'] = $config->qrCaption;
            $data['sponsorLogoPaths'] = $config->sponsorLogoPaths;
        }

        unset($data['config']);

        return $data;
    }

    /**
     * `config` is deliberately not in InfoscreenScene::$fillable (see the
     * model's comment: it must never be reachable via uncontrolled raw mass
     * assignment). The admin Filament layer is already gated by
     * InfoscreenScenePolicy::update, so it is the one place allowed to set
     * it explicitly, going through SceneConfigCast exactly as any other
     * write would. Mirrors EditFoodOrder::handleRecordUpdate.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $config = CreateInfoscreenScene::extractConfig($data);

        $record->update($data);

        if ($record instanceof InfoscreenScene) {
            $record->config = $config;
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
