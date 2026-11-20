<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes;

use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Schemas\RecipeForm;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Tables\RecipesTable;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RecipeResource extends Resource
{
    protected static ?string $model = GameRecipe::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?int $navigationSort = 48;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return __('game_recipes.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('game_recipes.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return RecipeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecipesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecipes::route('/'),
            'create' => CreateRecipe::route('/create'),
            'edit' => EditRecipe::route('/{record}/edit'),
        ];
    }
}
