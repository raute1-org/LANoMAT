<?php

namespace App\Modules\Voting\Filament\Resources\Polls;

use App\Modules\Voting\Filament\Resources\Polls\Pages\CreatePoll;
use App\Modules\Voting\Filament\Resources\Polls\Pages\EditPoll;
use App\Modules\Voting\Filament\Resources\Polls\Pages\ListPolls;
use App\Modules\Voting\Filament\Resources\Polls\Schemas\PollForm;
use App\Modules\Voting\Filament\Resources\Polls\Tables\PollsTable;
use App\Modules\Voting\Models\Poll;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PollResource extends Resource
{
    protected static ?string $model = Poll::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?int $navigationSort = 44;

    public static function getModelLabel(): string
    {
        return __('polls.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('polls.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return PollForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PollsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPolls::route('/'),
            'create' => CreatePoll::route('/create'),
            'edit' => EditPoll::route('/{record}/edit'),
        ];
    }
}
