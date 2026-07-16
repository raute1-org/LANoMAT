<?php

namespace App\Modules\Infoscreen\Filament\Resources\TombolaPrizes;

use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Pages\CreateTombolaPrize;
use App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Pages\EditTombolaPrize;
use App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Pages\ListTombolaPrizes;
use App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Schemas\TombolaPrizeForm;
use App\Modules\Infoscreen\Filament\Resources\TombolaPrizes\Tables\TombolaPrizesTable;
use App\Modules\Infoscreen\Models\TombolaPrize;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Orga CRUD of tombola prizes (draw eligibility itself is entirely governed
 * by {@see DrawTombola} against checked-in
 * registrations — this resource only maintains the prize catalogue).
 * Auto-discovered by AdminPanelProvider's `Modules/Infoscreen/Filament/Resources`
 * scan, same as InfoscreenSceneResource.
 */
class TombolaPrizeResource extends Resource
{
    protected static ?string $model = TombolaPrize::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    public static function getNavigationGroup(): ?string
    {
        return __('infoscreen.admin.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('infoscreen.tombola_resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('infoscreen.tombola_resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return TombolaPrizeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TombolaPrizesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTombolaPrizes::route('/'),
            'create' => CreateTombolaPrize::route('/create'),
            'edit' => EditTombolaPrize::route('/{record}/edit'),
        ];
    }
}
