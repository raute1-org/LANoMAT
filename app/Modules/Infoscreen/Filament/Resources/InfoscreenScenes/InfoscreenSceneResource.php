<?php

namespace App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes;

use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\CreateInfoscreenScene;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\EditInfoscreenScene;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\ListInfoscreenScenes;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Schemas\InfoscreenSceneForm;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Tables\InfoscreenScenesTable;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InfoscreenSceneResource extends Resource
{
    protected static ?string $model = InfoscreenScene::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTv;

    public static function getNavigationGroup(): ?string
    {
        return __('infoscreen.admin.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('infoscreen.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('infoscreen.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return InfoscreenSceneForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InfoscreenScenesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInfoscreenScenes::route('/'),
            'create' => CreateInfoscreenScene::route('/create'),
            'edit' => EditInfoscreenScene::route('/{record}/edit'),
        ];
    }
}
