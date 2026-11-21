<?php

namespace App\Modules\Catering\Filament\Resources\Restaurants;

use App\Modules\Catering\Filament\Resources\Restaurants\Pages\CreateRestaurant;
use App\Modules\Catering\Filament\Resources\Restaurants\Pages\EditRestaurant;
use App\Modules\Catering\Filament\Resources\Restaurants\Pages\ListRestaurants;
use App\Modules\Catering\Filament\Resources\Restaurants\Schemas\RestaurantForm;
use App\Modules\Catering\Filament\Resources\Restaurants\Tables\RestaurantsTable;
use App\Modules\Catering\Models\Restaurant;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?int $navigationSort = 43;

    public static function getModelLabel(): string
    {
        return __('catering.restaurant.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catering.restaurant.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return RestaurantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestaurantsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurants::route('/'),
            'create' => CreateRestaurant::route('/create'),
            'edit' => EditRestaurant::route('/{record}/edit'),
        ];
    }
}
