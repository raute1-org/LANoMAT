<?php

namespace App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes;

use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\CreateInfoscreenScene;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\EditInfoscreenScene;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Pages\ListInfoscreenScenes;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Schemas\InfoscreenSceneForm;
use App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Tables\InfoscreenScenesTable;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InfoscreenSceneResource extends Resource
{
    protected static ?string $model = InfoscreenScene::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTv;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Infoscreen;

    protected static ?int $navigationSort = 50;

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
