<?php

namespace App\Modules\Teams\Filament\Resources\Teams;

use App\Modules\Teams\Filament\Resources\Teams\Pages\CreateTeam;
use App\Modules\Teams\Filament\Resources\Teams\Pages\EditTeam;
use App\Modules\Teams\Filament\Resources\Teams\Pages\ListTeams;
use App\Modules\Teams\Filament\Resources\Teams\Schemas\TeamForm;
use App\Modules\Teams\Filament\Resources\Teams\Tables\TeamsTable;
use App\Modules\Teams\Models\Team;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getModelLabel(): string
    {
        return __('teams.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('teams.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return TeamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }
}
