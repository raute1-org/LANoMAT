<?php

namespace App\Modules\Games\Filament\Resources\Games;

use App\Modules\Games\Filament\Resources\Games\Pages\CreateGame;
use App\Modules\Games\Filament\Resources\Games\Pages\EditGame;
use App\Modules\Games\Filament\Resources\Games\Pages\ListGames;
use App\Modules\Games\Filament\Resources\Games\Schemas\GameForm;
use App\Modules\Games\Filament\Resources\Games\Tables\GamesTable;
use App\Modules\Games\Models\Game;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class GameResource extends Resource
{
    protected static ?string $model = Game::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 31;

    public static function getModelLabel(): string
    {
        return __('games.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('games.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return GameForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GamesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGames::route('/'),
            'create' => CreateGame::route('/create'),
            'edit' => EditGame::route('/{record}/edit'),
        ];
    }
}
