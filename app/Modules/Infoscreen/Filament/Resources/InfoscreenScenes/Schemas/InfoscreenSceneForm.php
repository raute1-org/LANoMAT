<?php

namespace App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Schemas;

use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Tournaments\Models\Tournament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class InfoscreenSceneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('infoscreen.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                Select::make('type')
                    ->label(__('infoscreen.fields.type'))
                    ->options(collect(SceneType::cases())
                        // Winner/Gong are synthetic and override-only
                        // (dispatched by BroadcastWinnerMoment/
                        // GongOnMatchLive, never a configured rotation
                        // entry) — excluded from this form.
                        ->reject(fn (SceneType $type) => $type === SceneType::Winner || $type === SceneType::Gong)
                        ->mapWithKeys(fn (SceneType $type) => [$type->value => $type->label()])
                        ->all())
                    ->required()
                    ->live(),
                TextInput::make('duration_sec')
                    ->label(__('infoscreen.fields.duration_sec'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(3),

                // Per-type config fields. `config` itself is not fillable
                // (see InfoscreenScene::$fillable), so these flat keys are
                // marshalled into a SceneConfig by the Create/Edit pages,
                // mirroring Catering's menu/MenuCast handling.
                TextInput::make('headline')
                    ->label(__('infoscreen.fields.headline'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                        SceneType::Announcement->value,
                        SceneType::Status->value,
                    ], true)),
                Textarea::make('body')
                    ->label(__('infoscreen.fields.body'))
                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                        SceneType::Announcement->value,
                        SceneType::Status->value,
                    ], true)),
                Select::make('tournamentId')
                    ->label(__('infoscreen.fields.tournament'))
                    ->options(fn (): array => Tournament::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                        SceneType::Bracket->value,
                        SceneType::UpcomingMatches->value,
                    ], true)),
                TextInput::make('qrPayload')
                    ->label(__('infoscreen.fields.qr_payload'))
                    ->maxLength(2048)
                    ->visible(fn (Get $get): bool => $get('type') === SceneType::PaymentQr->value),
                TextInput::make('qrCaption')
                    ->label(__('infoscreen.fields.qr_caption'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('type') === SceneType::PaymentQr->value),
                Repeater::make('sponsorLogoPaths')
                    ->label(__('infoscreen.fields.sponsor_logos'))
                    ->simple(
                        FileUpload::make('path')
                            ->label(__('infoscreen.fields.sponsor_logo'))
                            ->disk('public')
                            ->directory('sponsors')
                            ->image(),
                    )
                    ->addActionLabel(__('infoscreen.fields.sponsor_logo_add'))
                    ->defaultItems(0)
                    ->visible(fn (Get $get): bool => $get('type') === SceneType::Sponsors->value),
            ]);
    }
}
