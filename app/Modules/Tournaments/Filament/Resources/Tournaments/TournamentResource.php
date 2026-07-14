<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments;

use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\CreateTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\EditTournament;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\ListTournaments;
use App\Modules\Tournaments\Filament\Resources\Tournaments\RelationManagers\EntriesRelationManager;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Schemas\TournamentForm;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Tables\TournamentsTable;
use App\Modules\Tournaments\Models\Tournament;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TournamentResource extends Resource
{
    protected static ?string $model = Tournament::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    public static function getModelLabel(): string
    {
        return __('tournaments.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tournaments.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return TournamentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TournamentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTournaments::route('/'),
            'create' => CreateTournament::route('/create'),
            'edit' => EditTournament::route('/{record}/edit'),
        ];
    }
}
