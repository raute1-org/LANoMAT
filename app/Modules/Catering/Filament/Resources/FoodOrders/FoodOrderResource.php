<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders;

use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\CreateFoodOrder;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\EditFoodOrder;
use App\Modules\Catering\Filament\Resources\FoodOrders\Pages\ListFoodOrders;
use App\Modules\Catering\Filament\Resources\FoodOrders\RelationManagers\ItemsRelationManager;
use App\Modules\Catering\Filament\Resources\FoodOrders\Schemas\FoodOrderForm;
use App\Modules\Catering\Filament\Resources\FoodOrders\Tables\FoodOrdersTable;
use App\Modules\Catering\Models\FoodOrder;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FoodOrderResource extends Resource
{
    protected static ?string $model = FoodOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCake;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?int $navigationSort = 42;

    public static function getModelLabel(): string
    {
        return __('catering.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catering.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return FoodOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FoodOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFoodOrders::route('/'),
            'create' => CreateFoodOrder::route('/create'),
            'edit' => EditFoodOrder::route('/{record}/edit'),
        ];
    }
}
